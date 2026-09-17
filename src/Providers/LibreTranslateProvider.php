<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : LibreTranslateProvider.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Providers;

use GuzzleHttp\Client as HttpClient;
use Psr\Log\LoggerInterface;
use TranslationToolkit\Entities\{GlossaryEntry, TranslateOptions, TranslationResult};
use TranslationToolkit\Enums\TextFormat;
use TranslationToolkit\Exceptions\TranslationException;

/**
 * LibreTranslate (selbst gehostet): On-Premise-Übersetzung ohne natives
 * Glossar. Terminologie wird stattdessen über Token-Maskierung erzwungen —
 * Glossarbegriffe werden vor dem Aufruf durch übersetzungsstabile Token
 * ersetzt und danach durch die Zielübersetzung. Dadurch deterministisch,
 * qualitativ aber unter DeepL/Azure (Pivot über Englisch).
 *
 * Der API-Key ist optional (öffentliche Instanzen ohne Schlüssel), weshalb
 * dieser Provider — anders als DeepL/Azure — immer als verfügbar gilt.
 */
class LibreTranslateProvider extends AbstractHttpTranslationProvider {
    public const NAME = 'libretranslate';

    private const DEFAULT_BASE_URL = 'http://localhost:5000';

    /** Maskierungs-Token: bewusst ohne Sonderzeichen, damit MT sie nicht zerlegt. */
    private const TOKEN_FORMAT = 'XLTTERM%dX';

    /** @var list<string>|null Lazy von /languages geholt */
    private ?array $targetLanguages = null;

    public function __construct(
        ?string $baseUrl = null,
        #[\SensitiveParameter]
        private readonly ?string $apiKey = null,
        ?LoggerInterface $logger = null,
        ?HttpClient $httpClient = null,
    ) {
        parent::__construct(rtrim($baseUrl ?: self::DEFAULT_BASE_URL, '/'), $logger, false, $httpClient);
    }

    protected function providerLabel(): string {
        return 'LibreTranslate';
    }

    public function getName(): string {
        return self::NAME;
    }

    public function isAvailable(): bool {
        return true;
    }

    public function supportsGlossary(): bool {
        return true;
    }

    public function preflight(): void {
        $this->send('GET', '/languages');
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
        if ($texts === []) {
            return [];
        }

        $options ??= new TranslateOptions;
        $entries = $options->glossaryEntries();

        // Pro Text maskieren — nur getroffene Begriffe landen in der
        // jeweiligen Rückersetzungs-Tabelle
        $maskedTexts = [];
        $tokenTables = [];
        foreach ($texts as $i => $text) {
            [$maskedTexts[$i], $tokenTables[$i]] = self::maskTerms($text, $entries);
        }

        $payload = [
            'q' => $maskedTexts,
            'source' => $sourceLang !== null && $sourceLang !== '' ? strtolower($sourceLang) : 'auto',
            'target' => strtolower($targetLang),
            'format' => $options->format === TextFormat::Html ? 'html' : 'text',
        ];
        if ($this->apiKey !== null && trim($this->apiKey) !== '') {
            $payload['api_key'] = $this->apiKey;
        }

        $data = $this->decodeJsonResponse($this->send('POST', '/translate', ['json' => $payload]));

        // Bei Array-q liefert LibreTranslate translatedText/detectedLanguage als Arrays
        $translatedTexts = $data['translatedText'] ?? null;
        if (!is_array($translatedTexts) || count($translatedTexts) !== count($texts)) {
            throw new TranslationException('Unerwartete LibreTranslate-Antwort: translatedText passt nicht zur Anfrage');
        }

        $detectedLanguages = $data['detectedLanguage'] ?? [];

        $results = [];
        foreach ($texts as $i => $text) {
            $translated = $translatedTexts[$i] ?? null;
            if (!is_string($translated)) {
                throw new TranslationException('Unerwartete LibreTranslate-Antwort: translatedText[' . $i . '] fehlt');
            }

            $tokens = $tokenTables[$i];
            $detected = is_array($detectedLanguages) ? ($detectedLanguages[$i]['language'] ?? null) : null;

            $results[] = new TranslationResult(
                text: strtr($translated, $tokens),
                sourceText: $text,
                targetLang: strtolower($targetLang),
                detectedSourceLang: is_string($detected) && $detected !== '' ? strtolower($detected) : null,
                provider: self::NAME,
                charCount: mb_strlen($text),
                fromCache: false,
                deterministicTerminology: $tokens !== [],
            );
        }

        return $results;
    }

    /**
     * Zielsprachen laut `/languages`; Ergebnis wird für die Lebensdauer der
     * Instanz gemerkt.
     */
    public function getSupportedTargetLanguages(): array {
        if ($this->targetLanguages !== null) {
            return $this->targetLanguages;
        }

        $data = $this->decodeJsonResponse($this->send('GET', '/languages'));

        $codes = [];
        foreach ($data as $language) {
            $code = is_array($language) ? ($language['code'] ?? null) : null;
            if (is_string($code) && $code !== '') {
                $codes[] = strtolower($code);
            }
        }
        sort($codes);

        return $this->targetLanguages = array_values(array_unique($codes));
    }

    /**
     * Glossarbegriffe durch übersetzungsstabile Token ersetzen. Nur tatsächlich
     * getroffene Begriffe landen in der Rückersetzungs-Tabelle.
     *
     * @param list<GlossaryEntry> $entries
     * @return array{0: string, 1: array<string, string>} [maskierter Text, Token -> Zielübersetzung]
     */
    private static function maskTerms(string $text, array $entries): array {
        $tokens = [];
        foreach ($entries as $i => $entry) {
            $token = sprintf(self::TOKEN_FORMAT, $i);
            $count = 0;
            $masked = preg_replace('/' . preg_quote($entry->term, '/') . '/iu', $token, $text, -1, $count);
            if ($masked !== null && $count > 0) {
                $text = $masked;
                $tokens[$token] = $entry->translation;
            }
        }

        return [$text, $tokens];
    }
}
