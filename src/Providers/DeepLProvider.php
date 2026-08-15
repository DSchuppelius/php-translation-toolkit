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

use TranslationToolkit\Contracts\Interfaces\TranslationProviderInterface;
use TranslationToolkit\Entities\TranslationResult;
use TranslationToolkit\Exceptions\TranslationException;

/**
 * DeepL-Provider auf Basis der REST-API v2 (schlanker curl-Client, ohne SDK).
 *
 * Free-Keys (Suffix ":fx") werden automatisch gegen api-free.deepl.com
 * aufgelöst, alle anderen gegen api.deepl.com.
 *
 * Nicht final, damit Tests request() mit vorbereiteten Antworten überschreiben können.
 */
class DeepLProvider implements TranslationProviderInterface {
    public const NAME = 'deepl';

    private const API_URL_PRO = 'https://api.deepl.com';
    private const API_URL_FREE = 'https://api-free.deepl.com';

    /** @var list<string> DeepL-Zielsprachen (ISO 639-1, Stand 2026) */
    private const TARGET_LANGUAGES = [
        'ar', 'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'he',
        'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'nb', 'nl', 'pl', 'pt', 'ro',
        'ru', 'sk', 'sl', 'sv', 'th', 'tr', 'uk', 'vi', 'zh',
    ];

    private readonly string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        ?string $baseUrl = null,
        private readonly int $timeoutSeconds = 10,
    ) {
        $this->baseUrl = rtrim(
            $baseUrl ?? (str_ends_with($apiKey, ':fx') ? self::API_URL_FREE : self::API_URL_PRO),
            '/'
        );
    }

    public function getName(): string {
        return self::NAME;
    }

    public function isAvailable(): bool {
        return trim($this->apiKey) !== '';
    }

    public function getBaseUrl(): string {
        return $this->baseUrl;
    }

    public function getSupportedTargetLanguages(): array {
        return self::TARGET_LANGUAGES;
    }

    public function translate(string $text, string $targetLang, ?string $sourceLang = null): TranslationResult {
        if (!$this->isAvailable()) {
            throw new TranslationException('DeepL-API-Key ist nicht konfiguriert');
        }

        $payload = [
            'text' => [$text],
            'target_lang' => strtoupper($targetLang),
        ];
        if ($sourceLang !== null && $sourceLang !== '') {
            $payload['source_lang'] = strtoupper($sourceLang);
        }

        $response = $this->request('POST', '/v2/translate', $payload);

        $translation = $response['translations'][0] ?? null;
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
        );
    }

    /**
     * Zeichenverbrauch des DeepL-Kontos (GET /v2/usage).
     *
     * @return array{characterCount: int, characterLimit: int}
     */
    public function getUsage(): array {
        $response = $this->request('GET', '/v2/usage', []);

        return [
            'characterCount' => (int) ($response['character_count'] ?? 0),
            'characterLimit' => (int) ($response['character_limit'] ?? 0),
        ];
    }

    /**
     * Führt einen API-Request aus und dekodiert die JSON-Antwort.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws TranslationException
     */
    protected function request(string $method, string $path, array $payload): array {
        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new TranslationException('curl konnte nicht initialisiert werden');
        }

        $headers = ['Authorization: DeepL-Auth-Key ' . $this->apiKey];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ];

        if ($method === 'POST') {
            try {
                $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $e) {
                curl_close($handle);
                throw new TranslationException('Payload konnte nicht kodiert werden: ' . $e->getMessage(), 0, $e);
            }
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
            $headers[] = 'Content-Type: application/json';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new TranslationException('DeepL-Anfrage fehlgeschlagen: ' . $error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($status === 403) {
            throw new TranslationException('DeepL-Authentifizierung fehlgeschlagen (HTTP 403) — API-Key prüfen');
        }
        if ($status === 456) {
            throw new TranslationException('DeepL-Zeichenkontingent erschöpft (HTTP 456)');
        }
        if ($status >= 400) {
            throw new TranslationException(sprintf('DeepL-Fehler HTTP %d: %s', $status, substr((string) $responseBody, 0, 300)));
        }

        try {
            $decoded = json_decode((string) $responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TranslationException('DeepL-Antwort ist kein gültiges JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new TranslationException('DeepL-Antwort hat ein unerwartetes Format');
        }

        return $decoded;
    }
}
