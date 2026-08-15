<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : AzureTranslatorProvider.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Providers;

use APIToolkit\API\Authentication\ApiKeyAuthentication;
use GuzzleHttp\Client as HttpClient;
use Psr\Log\LoggerInterface;
use TranslationToolkit\Entities\{GlossaryEntry, TranslateOptions, TranslationResult};
use TranslationToolkit\Enums\TextFormat;
use TranslationToolkit\Exceptions\TranslationException;

/**
 * Azure Translator (REST v3.0): `POST /translate` mit
 * `Ocp-Apim-Subscription-Key` und — bei regionalen Ressourcen — zusätzlich
 * `Ocp-Apim-Subscription-Region`.
 *
 * Terminologie wird deterministisch je Request über Dynamic-Dictionary-Markup
 * erzwungen (`<mstrans:dictionary translation="…">Begriff</mstrans:dictionary>`);
 * ein Glossar-Sync entfällt. Das Markup verlangt `textType=html`, weshalb bei
 * gesetztem Glossar automatisch auf HTML umgeschaltet wird.
 */
class AzureTranslatorProvider extends AbstractHttpTranslationProvider {
    public const NAME = 'azure_translator';

    private const API_VERSION = '3.0';
    private const DEFAULT_BASE_URL = 'https://api.cognitive.microsofttranslator.com';

    /** Maximale Textanzahl pro /translate-Request laut Azure-API */
    private const MAX_BATCH_SIZE = 100;

    /** @var list<string>|null Lazy von /languages geholt */
    private ?array $targetLanguages = null;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly ?string $region = null,
        ?string $baseUrl = null,
        ?LoggerInterface $logger = null,
        ?HttpClient $httpClient = null,
    ) {
        parent::__construct(rtrim($baseUrl ?: self::DEFAULT_BASE_URL, '/'), $logger, false, $httpClient);

        if ($this->isAvailable()) {
            $this->setAuthentication(new ApiKeyAuthentication(
                $apiKey,
                'Ocp-Apim-Subscription-Key',
                $region !== null && $region !== '' ? ['Ocp-Apim-Subscription-Region' => $region] : []
            ));
        }
    }

    protected function providerLabel(): string {
        return 'Azure Translator';
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

    public function preflight(): void {
        // Billigster echter Aufruf mit Schlüsselprüfung (4 Zeichen).
        $this->assertAvailable();
        $this->send('POST', $this->translatePath('en', null, false), ['json' => [['Text' => 'ping']]]);
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        ?TranslateOptions $options = null,
    ): TranslationResult {
        return $this->translateBatch([$text], $targetLang, $sourceLang, $options)[0];
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        ?TranslateOptions $options = null,
    ): array {
        $this->assertAvailable();

        if ($texts === []) {
            return [];
        }

        $options ??= new TranslateOptions;
        $entries = $options->glossaryEntries();
        $path = $this->translatePath($targetLang, $sourceLang, $options->format === TextFormat::Html || $entries !== []);

        $results = [];
        foreach (array_chunk($texts, self::MAX_BATCH_SIZE) as $chunk) {

            $body = array_map(
                static fn (string $text): array => ['Text' => self::applyDynamicDictionary($text, $entries)],
                $chunk
            );

            $data = $this->decodeJsonResponse($this->send('POST', $path, ['json' => $body]));
            if (count($data) !== count($chunk)) {
                throw new TranslationException('Unerwartete Azure-Antwort: Anzahl der Übersetzungen passt nicht zur Anfrage');
            }

            foreach ($chunk as $i => $text) {
                $element = $data[$i] ?? null;
                if (!is_array($element)) {
                    throw new TranslationException('Unerwartete Azure-Antwort: leeres Ergebnis-Element');
                }

                $translated = $element['translations'][0]['text'] ?? null;
                if (!is_string($translated)) {
                    throw new TranslationException('Unerwartete Azure-Antwort: translations[0].text fehlt');
                }

                $detected = $element['detectedLanguage']['language'] ?? null;

                $results[] = new TranslationResult(
                    text: $translated,
                    sourceText: $text,
                    targetLang: strtolower($targetLang),
                    detectedSourceLang: is_string($detected) && $detected !== '' ? strtolower($detected) : null,
                    provider: self::NAME,
                    charCount: mb_strlen($text),
                    fromCache: false,
                    deterministicTerminology: $entries !== [],
                );
            }
        }

        return $results;
    }

    /**
     * Zielsprachen laut `/languages` (öffentlich, ohne Schlüssel); Ergebnis
     * wird für die Lebensdauer der Instanz gemerkt.
     */
    public function getSupportedTargetLanguages(): array {
        if ($this->targetLanguages !== null) {
            return $this->targetLanguages;
        }

        $data = $this->decodeJsonResponse($this->send(
            'GET',
            '/languages?' . http_build_query(['api-version' => self::API_VERSION, 'scope' => 'translation'])
        ));

        $translation = $data['translation'] ?? null;
        if (!is_array($translation)) {
            throw new TranslationException('Unerwartete Azure-Antwort: translation-Abschnitt fehlt');
        }

        $codes = array_map(strtolower(...), array_map(strval(...), array_keys($translation)));
        sort($codes);

        return $this->targetLanguages = $codes;
    }

    private function translatePath(string $targetLang, ?string $sourceLang, bool $html): string {
        $query = [
            'api-version' => self::API_VERSION,
            'to' => strtolower($targetLang),
        ];
        if ($sourceLang !== null && $sourceLang !== '') {
            $query['from'] = strtolower($sourceLang);
        }
        if ($html) {
            $query['textType'] = 'html';
        }

        return '/translate?' . http_build_query($query);
    }

    /**
     * Glossarbegriffe in Dictionary-Markup einfassen. Der Quellbegriff bleibt
     * im Text stehen — Azure ersetzt ihn durch die vorgegebene Übersetzung.
     *
     * @param list<GlossaryEntry> $entries
     */
    private static function applyDynamicDictionary(string $text, array $entries): string {
        foreach ($entries as $entry) {
            $replaced = preg_replace(
                '/' . preg_quote($entry->term, '/') . '/iu',
                sprintf(
                    '<mstrans:dictionary translation="%s">%s</mstrans:dictionary>',
                    htmlspecialchars($entry->translation, ENT_QUOTES),
                    $entry->term
                ),
                $text
            );
            if ($replaced !== null) {
                $text = $replaced;
            }
        }

        return $text;
    }

    private function assertAvailable(): void {
        if (!$this->isAvailable()) {
            throw new TranslationException('Azure-Translator-Schlüssel ist nicht konfiguriert');
        }
    }
}
