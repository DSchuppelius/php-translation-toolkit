<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : AbstractHttpTranslationProvider.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Providers;

use APIToolkit\Contracts\Abstracts\API\ClientAbstract;
use APIToolkit\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TranslationToolkit\Contracts\Interfaces\TranslationProviderInterface;
use TranslationToolkit\Exceptions\TranslationException;

/**
 * HTTP-Fundament der Übersetzungs-Provider: Requests laufen über den
 * Retry-Pfad des api-toolkit (Backoff, Rate-Limit-Handling, Log-Redaktion);
 * dessen typisierte Exceptions werden in die {@see TranslationException}
 * des Ports übersetzt.
 */
abstract class AbstractHttpTranslationProvider extends ClientAbstract implements TranslationProviderInterface {
    /** Anzeigename für Fehlermeldungen (z.B. "DeepL"). */
    abstract protected function providerLabel(): string;

    /**
     * @param array<string, mixed> $options
     */
    protected function send(string $method, string $path, array $options = []): ResponseInterface {
        // Das api-toolkit wiederholt nicht-idempotente Methoden seit 1.6 nicht
        // mehr, sobald der Request den Server erreicht haben koennte -- gegen
        // Doppel-Wirkungen wie doppelt angelegte Datensaetze. Uebersetzen ist
        // seiteneffektfrei: dieselbe Anfrage liefert dieselbe Antwort und legt
        // serverseitig nichts an, ein fehlgeschlagener Versuch hinterlaesst
        // also nichts, was ein zweiter verdoppeln koennte. Ohne dieses Opt-in
        // reicht ein einzelnes 503 oder 429 bis zum Aufrufer durch.
        $options['retry_non_idempotent'] ??= true;

        try {
            return $this->requestWithRetry($method, $path, $options);
        } catch (ApiException $e) {
            throw $this->translateApiException($e);
        } catch (Throwable $e) {
            throw new TranslationException(sprintf('%s-Anfrage fehlgeschlagen: %s', $this->providerLabel(), $e->getMessage()), 0, $e);
        }
    }

    /**
     * HTTP-Fehler -> Port-Exception; Provider können Sonderfälle ergänzen
     * (z.B. DeepL-Kontingent 456).
     */
    protected function translateApiException(ApiException $e): TranslationException {
        $label = $this->providerLabel();
        $code = $e->getCode();

        return match (true) {
            $code === 401 || $code === 403 => new TranslationException(sprintf('%s-Authentifizierung fehlgeschlagen (HTTP %d) — API-Key prüfen', $label, $code), $code, $e),
            $code === 429 => new TranslationException(sprintf('%s-Rate-Limit erreicht (HTTP 429)', $label), 429, $e),
            default => new TranslationException(sprintf('%s-Fehler HTTP %d: %s', $label, $code, $e->getMessage()), $code, $e),
        };
    }

    /**
     * Frühe, klare Fehlermeldung statt eines API-Fehlers — nur für Provider
     * mit statischer Sprachliste sinnvoll (lazy geladene Listen würden hier
     * einen zusätzlichen API-Aufruf provozieren).
     *
     * @param list<string> $supported
     */
    protected function assertSupportedTargetLanguage(string $targetLang, array $supported): void {
        if (!in_array(strtolower($targetLang), $supported, true)) {
            throw new TranslationException(sprintf(
                'Zielsprache "%s" wird von %s nicht unterstützt (verfügbar: %s)',
                $targetLang,
                $this->providerLabel(),
                implode(', ', $supported)
            ));
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function decodeJsonResponse(ResponseInterface $response): array {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TranslationException(sprintf('%s-Antwort ist kein gültiges JSON: %s', $this->providerLabel(), $e->getMessage()), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new TranslationException(sprintf('%s-Antwort hat ein unerwartetes Format', $this->providerLabel()));
        }

        return $decoded;
    }
}
