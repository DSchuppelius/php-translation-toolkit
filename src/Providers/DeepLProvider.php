<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DeepLProvider.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Providers;

use APIToolkit\API\Authentication\ApiKeyAuthentication;
use APIToolkit\Exceptions\ApiException;
use GuzzleHttp\Client as HttpClient;
use Psr\Log\LoggerInterface;
use TranslationToolkit\Contracts\Interfaces\GlossaryIdStoreInterface;
use TranslationToolkit\Entities\{GlossaryEntry, TranslateOptions, TranslationResult};
use TranslationToolkit\Enums\{Formality, TextFormat};
use TranslationToolkit\Exceptions\TranslationException;
use TranslationToolkit\Stores\InMemoryGlossaryIdStore;

/**
 * DeepL-Provider auf Basis der REST-API v2, gebaut auf dem api-toolkit
 * (Retry mit Backoff, Rate-Limit-Handling, Auth-Abstraktion, Log-Redaktion).
 *
 * Free-Keys (Suffix ":fx") werden automatisch gegen api-free.deepl.com
 * aufgelöst, alle anderen gegen api.deepl.com. Für Tests kann ein
 * vorbereiteter Guzzle-Client (MockHandler) injiziert werden.
 *
 * Terminologie wird über multilinguale v3-Glossare deterministisch erzwungen:
 * ein Glossar je Store, dessen Dictionary je Sprachpaar bei jedem Aufruf
 * idempotent voll ersetzt wird (damit wirken auch Löschungen sofort). DeepL
 * verlangt dafür eine **explizite Quellsprache** — ohne `sourceLang` bleibt
 * das Glossar ungenutzt und das Ergebnis meldet
 * `deterministicTerminology === false`.
 */
class DeepLProvider extends AbstractHttpTranslationProvider {
    public const NAME = 'deepl';

    private const API_URL_PRO = 'https://api.deepl.com';
    private const API_URL_FREE = 'https://api-free.deepl.com';

    /** DeepL-Kontingent erschöpft (nicht-standardisierter Statuscode) */
    private const HTTP_QUOTA_EXCEEDED = 456;

    /** @var list<string> DeepL-Zielsprachen (ISO 639-1, Stand 2026) */
    private const TARGET_LANGUAGES = [
        'ar', 'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'he',
        'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'nb', 'nl', 'pl', 'pt', 'ro',
        'ru', 'sk', 'sl', 'sv', 'th', 'tr', 'uk', 'vi', 'zh',
    ];

    private readonly GlossaryIdStoreInterface $glossaryIdStore;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $apiKey,
        ?string $baseUrl = null,
        ?LoggerInterface $logger = null,
        ?HttpClient $httpClient = null,
        ?GlossaryIdStoreInterface $glossaryIdStore = null,
        private readonly string $glossaryName = 'translation-toolkit',
    ) {
        parent::__construct(
            $baseUrl ?? (str_ends_with($apiKey, ':fx') ? self::API_URL_FREE : self::API_URL_PRO),
            $logger,
            false,
            $httpClient
        );

        $this->glossaryIdStore = $glossaryIdStore ?? new InMemoryGlossaryIdStore;

        if ($this->isAvailable()) {
            $this->setAuthentication(new ApiKeyAuthentication('DeepL-Auth-Key ' . $apiKey, 'Authorization'));
        }
    }

    protected function providerLabel(): string {
        return 'DeepL';
    }

    public function getName(): string {
        return self::NAME;
    }

    public function isAvailable(): bool {
        return trim($this->apiKey) !== '';
    }

    public function supportsGlossary(): bool {
        return true;
    }

    public function getSupportedTargetLanguages(): array {
        return self::TARGET_LANGUAGES;
    }

    public function preflight(): void {
        $this->getUsage();
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        ?TranslateOptions $options = null,
    ): TranslationResult {
        $this->assertAvailable();

        $options ??= new TranslateOptions;
        $glossaryId = $this->syncGlossary($sourceLang, $targetLang, $options);

        $payload = [
            'text' => [$text],
            'target_lang' => strtoupper($targetLang),
        ];
        if ($sourceLang !== null && $sourceLang !== '') {
            $payload['source_lang'] = strtoupper($sourceLang);
        }
        if ($options->format === TextFormat::Html) {
            $payload['tag_handling'] = 'html';
        }
        if ($options->formality !== Formality::Default) {
            $payload['formality'] = 'prefer_' . $options->formality->value;
        }
        if ($glossaryId !== null) {
            $payload['glossary_id'] = $glossaryId;
        }

        $data = $this->decodeJsonResponse($this->send('POST', '/v2/translate', ['json' => $payload]));

        $translation = $data['translations'][0] ?? null;
        if (!is_array($translation) || !isset($translation['text']) || !is_string($translation['text'])) {
            throw new TranslationException('Unerwartete DeepL-Antwort: translations[0].text fehlt');
        }

        $detected = $translation['detected_source_language'] ?? null;

        return new TranslationResult(
            text: $translation['text'],
            sourceText: $text,
            targetLang: strtolower($targetLang),
            detectedSourceLang: is_string($detected) && $detected !== '' ? strtolower($detected) : null,
            provider: self::NAME,
            charCount: mb_strlen($text),
            fromCache: false,
            deterministicTerminology: $glossaryId !== null,
        );
    }

    /**
     * Zeichenverbrauch des DeepL-Kontos (GET /v2/usage).
     *
     * @return array{characterCount: int, characterLimit: int}
     */
    public function getUsage(): array {
        $this->assertAvailable();

        $data = $this->decodeJsonResponse($this->send('GET', '/v2/usage'));

        return [
            'characterCount' => (int) ($data['character_count'] ?? 0),
            'characterLimit' => (int) ($data['character_limit'] ?? 0),
        ];
    }

    /**
     * Idempotenter Glossar-Sync (v3, multilingual): das Dictionary des
     * Sprachpaars wird voll ersetzt; ist das gemerkte Glossar remote
     * verschwunden, wird ein neues angelegt und die ID im Store abgelegt.
     *
     * @return string|null Glossar-ID oder null, wenn kein Glossar greift
     */
    private function syncGlossary(?string $sourceLang, string $targetLang, TranslateOptions $options): ?string {
        $entries = $options->glossaryEntries();
        if ($entries === [] || $sourceLang === null || $sourceLang === '') {
            return null;
        }

        $dictionary = [
            'source_lang' => strtoupper($sourceLang),
            'target_lang' => strtoupper($targetLang),
            'entries' => self::toTsv($entries),
            'entries_format' => 'tsv',
        ];

        $glossaryId = $this->glossaryIdStore->get();
        if ($glossaryId !== null && $glossaryId !== '') {
            try {
                $this->send('PUT', '/v3/glossaries/' . rawurlencode($glossaryId) . '/dictionaries', ['json' => $dictionary]);

                return $glossaryId;
            } catch (TranslationException) {
                // Glossar remote verschwunden oder nicht erreichbar → Neuanlage.
            }
        }

        $data = $this->decodeJsonResponse($this->send('POST', '/v3/glossaries', [
            'json' => ['name' => $this->glossaryName, 'dictionaries' => [$dictionary]],
        ]));

        $glossaryId = $data['glossary_id'] ?? null;
        if (!is_string($glossaryId) || $glossaryId === '') {
            throw new TranslationException('Glossar-Anlage ohne glossary_id beantwortet');
        }

        $this->glossaryIdStore->set($glossaryId);

        return $glossaryId;
    }

    /**
     * TSV-Zeilen „Begriff<TAB>Übersetzung"; Tabs/Zeilenumbrüche in den Werten
     * würden das Format sprengen und werden zu Leerzeichen.
     *
     * @param list<GlossaryEntry> $entries
     */
    private static function toTsv(array $entries): string {
        return implode("\n", array_map(
            static fn (GlossaryEntry $e): string => self::flatten($e->term) . "\t" . self::flatten($e->translation),
            $entries
        ));
    }

    private static function flatten(string $value): string {
        return str_replace(["\t", "\r\n", "\n", "\r"], ' ', $value);
    }

    private function assertAvailable(): void {
        if (!$this->isAvailable()) {
            throw new TranslationException('DeepL-API-Key ist nicht konfiguriert');
        }
    }

    protected function translateApiException(ApiException $e): TranslationException {
        if ($e->getCode() === self::HTTP_QUOTA_EXCEEDED) {
            return new TranslationException('DeepL-Zeichenkontingent erschöpft (HTTP 456)', self::HTTP_QUOTA_EXCEEDED, $e);
        }

        return parent::translateApiException($e);
    }
}
