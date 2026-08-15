<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TranslationService.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit;

use TranslationToolkit\Contracts\Interfaces\{
    TranslationCacheInterface,
    TranslationProviderInterface,
    TranslationUsageListenerInterface
};
use TranslationToolkit\Entities\{CachedTranslation, TranslateOptions, TranslationResult};
use TranslationToolkit\Exceptions\TranslationException;

/**
 * Orchestriert Übersetzungen: Cache prüfen → Provider aufrufen → Cache füllen
 * → Nutzung melden (ADR-0010-Flow).
 *
 * $defaultOptions (z.B. ein Mandanten-Glossar des Hosts) greifen immer dann,
 * wenn der Aufrufer keine eigenen Optionen übergibt.
 */
final class TranslationService {
    public function __construct(
        private readonly TranslationProviderInterface $provider,
        private readonly ?TranslationCacheInterface $cache = null,
        private readonly ?TranslationUsageListenerInterface $usageListener = null,
        private readonly string $defaultTargetLang = 'de',
        private readonly ?TranslateOptions $defaultOptions = null,
    ) {}

    public function getProviderName(): string {
        return $this->provider->getName();
    }

    public function getDefaultTargetLang(): string {
        return $this->defaultTargetLang;
    }

    public function isAvailable(): bool {
        return $this->provider->isAvailable();
    }

    /**
     * Übersetzt einen Text; identische Texte werden aus dem globalen Cache
     * bedient. Format, Förmlichkeit und Glossar gehen in den Cache-Schlüssel
     * ein — sie verändern das Ergebnis.
     *
     * Leere Texte werden ohne Provider-Aufruf unverändert zurückgegeben.
     */
    public function translate(
        string $text,
        ?string $targetLang = null,
        ?string $sourceLang = null,
        ?TranslateOptions $options = null,
    ): TranslationResult {
        $targetLang = strtolower($targetLang ?? $this->defaultTargetLang);
        $options ??= $this->defaultOptions;

        if (trim($text) === '') {
            return new TranslationResult(
                text: $text,
                sourceText: $text,
                targetLang: $targetLang,
                detectedSourceLang: null,
                provider: $this->provider->getName(),
                charCount: 0,
                fromCache: false,
            );
        }

        $hash = self::cacheHash($text, $sourceLang, $targetLang, $this->provider->getName(), $options);

        $cached = $this->cache?->get($hash);
        if ($cached !== null) {
            $result = self::fromCache($cached, $text, $targetLang, $this->provider->getName());
            $this->usageListener?->onTranslation($result);

            return $result;
        }

        $result = $this->provider->translate($text, $targetLang, $sourceLang, $options);
        $this->cache?->set($hash, $sourceLang, $result);
        $this->usageListener?->onTranslation($result);

        return $result;
    }

    /**
     * Ergebnis aus einem Cache-Treffer — trägt die Metadaten des
     * Ursprungslaufs (erkannte Quellsprache, erzwungene Terminologie).
     */
    private static function fromCache(CachedTranslation $cached, string $sourceText, string $targetLang, string $provider): TranslationResult {
        return new TranslationResult(
            text: $cached->text,
            sourceText: $sourceText,
            targetLang: $targetLang,
            detectedSourceLang: $cached->detectedSourceLang,
            provider: $provider,
            charCount: mb_strlen($sourceText),
            fromCache: true,
            deterministicTerminology: $cached->deterministicTerminology,
        );
    }

    /**
     * Übersetzt mehrere Texte: Cache-Treffer werden einzeln bedient, nur die
     * verbleibenden Texte gehen gebündelt an den Provider
     * ({@see TranslationProviderInterface::translateBatch()}).
     *
     * @param list<string> $texts
     * @return list<TranslationResult> Ergebnisse in Eingabereihenfolge
     */
    public function translateMany(
        array $texts,
        ?string $targetLang = null,
        ?string $sourceLang = null,
        ?TranslateOptions $options = null,
    ): array {
        $targetLang = strtolower($targetLang ?? $this->defaultTargetLang);
        $options ??= $this->defaultOptions;

        $results = [];
        $missIndexes = [];
        $missTexts = [];

        foreach ($texts as $i => $text) {
            if (trim($text) === '') {
                $results[$i] = new TranslationResult(
                    text: $text,
                    sourceText: $text,
                    targetLang: $targetLang,
                    detectedSourceLang: null,
                    provider: $this->provider->getName(),
                    charCount: 0,
                    fromCache: false,
                );
                continue;
            }

            $hash = self::cacheHash($text, $sourceLang, $targetLang, $this->provider->getName(), $options);
            $cached = $this->cache?->get($hash);
            if ($cached !== null) {
                $result = self::fromCache($cached, $text, $targetLang, $this->provider->getName());
                $this->usageListener?->onTranslation($result);
                $results[$i] = $result;
                continue;
            }

            $missIndexes[] = $i;
            $missTexts[] = $text;
        }

        if ($missTexts !== []) {
            $translated = $this->provider->translateBatch($missTexts, $targetLang, $sourceLang, $options);
            if (count($translated) !== count($missTexts)) {
                throw new TranslationException('Provider lieferte eine abweichende Anzahl Batch-Ergebnisse');
            }

            foreach ($translated as $k => $result) {
                $hash = self::cacheHash($missTexts[$k], $sourceLang, $targetLang, $this->provider->getName(), $options);
                $this->cache?->set($hash, $sourceLang, $result);
                $this->usageListener?->onTranslation($result);
                $results[$missIndexes[$k]] = $result;
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * v1-Heuristik: Übersetzung lohnt bei nicht-lateinischen Zeichen und
     * einer Mindestlänge (Legacy: strlen > 12).
     */
    public static function needsTranslation(string $text, int $minLength = 13): bool {
        if (mb_strlen(trim($text)) < $minLength) {
            return false;
        }

        return self::containsNonLatinText($text);
    }

    /**
     * Enthält der Text Zeichen außerhalb von Latin/Common/Inherited (z.B.
     * Kyrillisch, Han, Katakana)?
     */
    public static function containsNonLatinText(string $text): bool {
        return preg_match('/[^\p{Latin}\p{Common}\p{Inherited}]/u', $text) === 1;
    }

    /**
     * Cache-Schlüssel nach ADR-0010: SHA-256(text|source|target|provider).
     * Nicht-Default-Optionen hängen ihren Fingerprint an — bei Defaults bleibt
     * der Schlüssel identisch zu Einträgen ohne Optionen.
     */
    public static function cacheHash(
        string $text,
        ?string $sourceLang,
        string $targetLang,
        string $provider,
        ?TranslateOptions $options = null,
    ): string {
        $key = $text . '|' . strtolower($sourceLang ?? 'auto') . '|' . strtolower($targetLang) . '|' . $provider;

        if ($options !== null && !$options->isDefault()) {
            $key .= '|' . $options->fingerprint();
        }

        return hash('sha256', $key);
    }
}
