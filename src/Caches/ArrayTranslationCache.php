<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ArrayTranslationCache.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Caches;

use TranslationToolkit\Contracts\Interfaces\TranslationCacheInterface;
use TranslationToolkit\Entities\{CachedTranslation, TranslationResult};

/**
 * In-Memory-Cache für Tests und Kurzläufer-Prozesse.
 * Persistente Implementierungen (DB) leben beim jeweiligen Host-Projekt.
 */
final class ArrayTranslationCache implements TranslationCacheInterface {
    /** @var array<string, CachedTranslation> */
    private array $entries = [];

    public function get(string $hash): ?CachedTranslation {
        return $this->entries[$hash] ?? null;
    }

    public function set(string $hash, ?string $sourceLang, TranslationResult $result): void {
        $this->entries[$hash] = new CachedTranslation(
            text: $result->text,
            detectedSourceLang: $result->detectedSourceLang,
            deterministicTerminology: $result->deterministicTerminology,
        );
    }

    public function count(): int {
        return count($this->entries);
    }
}
