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
use TranslationToolkit\Entities\{TranslateOptions, TranslationResult};

/**
 * Orchestriert Übersetzungen: Cache prüfen → Provider aufrufen → Cache füllen
 * → Nutzung melden (ADR-0010-Flow).
 */
final class TranslationService {
    public function __construct(
        private readonly TranslationProviderInterface $provider,
        private readonly ?TranslationCacheInterface $cache = null,
        private readonly ?TranslationUsageListenerInterface $usageListener = null,
        private readonly string $defaultTargetLang = 'de',
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
            $result = new TranslationResult(
                text: $cached,
                sourceText: $text,
                targetLang: $targetLang,
                detectedSourceLang: null,
                provider: $this->provider->getName(),
                charCount: mb_strlen($text),
                fromCache: true,
                // Bewusst false: in diesem Aufruf wurde nichts erzwungen, und
                // ob der ursprüngliche Lauf es tat, weiß der Cache nicht (er
                // hält nur den Text). Der Schlüssel enthält Glossar UND
                // Quellsprache — ein Treffer stammt also garantiert aus einem
                // Lauf mit identischen Vorgaben.
                deterministicTerminology: false,
            );
            $this->usageListener?->onTranslation($result);

            return $result;
        }

        $result = $this->provider->translate($text, $targetLang, $sourceLang, $options);
        $this->cache?->set($hash, $text, $sourceLang, $targetLang, $result->text, $this->provider->getName());
        $this->usageListener?->onTranslation($result);

        return $result;
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
