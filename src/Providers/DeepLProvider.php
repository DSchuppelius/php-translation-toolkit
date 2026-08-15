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
use APIToolkit\Contracts\Abstracts\API\ClientAbstract;
use APIToolkit\Exceptions\{ApiException, ForbiddenException, TooManyRequestsException};
use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TranslationToolkit\Contracts\Interfaces\TranslationProviderInterface;
use TranslationToolkit\Entities\TranslationResult;
use TranslationToolkit\Exceptions\TranslationException;

/**
 * DeepL-Provider auf Basis der REST-API v2, gebaut auf dem api-toolkit
 * (Retry mit Backoff, Rate-Limit-Handling, Auth-Abstraktion, Log-Redaktion).
 *
 * Free-Keys (Suffix ":fx") werden automatisch gegen api-free.deepl.com
 * aufgelöst, alle anderen gegen api.deepl.com. Für Tests kann ein
 * vorbereiteter Guzzle-Client (MockHandler) injiziert werden.
 */
class DeepLProvider extends ClientAbstract implements TranslationProviderInterface {
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

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $apiKey,
        ?string $baseUrl = null,
        ?LoggerInterface $logger = null,
        ?HttpClient $httpClient = null,
    ) {
        parent::__construct(
            $baseUrl ?? (str_ends_with($apiKey, ':fx') ? self::API_URL_FREE : self::API_URL_PRO),
            $logger,
            false,
            $httpClient
        );

        if ($this->isAvailable()) {
            $this->setAuthentication(new ApiKeyAuthentication('DeepL-Auth-Key ' . $apiKey, 'Authorization'));
        }
    }

    public function getName(): string {
        return self::NAME;
    }

    public function isAvailable(): bool {
        return trim($this->apiKey) !== '';
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

        $response = $this->send('POST', '/v2/translate', ['json' => $payload]);
        $data = self::decodeJson($response);

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
        );
    }

    /**
     * Zeichenverbrauch des DeepL-Kontos (GET /v2/usage).
     *
     * @return array{characterCount: int, characterLimit: int}
     */
    public function getUsage(): array {
        if (!$this->isAvailable()) {
            throw new TranslationException('DeepL-API-Key ist nicht konfiguriert');
        }

        $response = $this->send('GET', '/v2/usage');
        $data = self::decodeJson($response);

        return [
            'characterCount' => (int) ($data['character_count'] ?? 0),
            'characterLimit' => (int) ($data['character_limit'] ?? 0),
        ];
    }

    /**
     * Führt den Request über den Retry-Pfad des api-toolkit aus und übersetzt
     * dessen typisierte Exceptions in die TranslationException des Ports.
     *
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, array $options = []): ResponseInterface {
        try {
            return $this->requestWithRetry($method, $path, $options);
        } catch (ForbiddenException $e) {
            throw new TranslationException('DeepL-Authentifizierung fehlgeschlagen (HTTP 403) — API-Key prüfen', 403, $e);
        } catch (TooManyRequestsException $e) {
            throw new TranslationException('DeepL-Rate-Limit erreicht (HTTP 429)', 429, $e);
        } catch (ApiException $e) {
            if ($e->getCode() === self::HTTP_QUOTA_EXCEEDED) {
                throw new TranslationException('DeepL-Zeichenkontingent erschöpft (HTTP 456)', self::HTTP_QUOTA_EXCEEDED, $e);
            }

            throw new TranslationException(sprintf('DeepL-Fehler HTTP %d: %s', $e->getCode(), $e->getMessage()), $e->getCode(), $e);
        } catch (Throwable $e) {
            throw new TranslationException('DeepL-Anfrage fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(ResponseInterface $response): array {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TranslationException('DeepL-Antwort ist kein gültiges JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new TranslationException('DeepL-Antwort hat ein unerwartetes Format');
        }

        return $decoded;
    }
}
