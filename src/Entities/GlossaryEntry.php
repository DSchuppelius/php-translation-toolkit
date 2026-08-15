<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GlossaryEntry.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Entities;

/**
 * Ein zu erzwingender Glossarbegriff: Quellterm → feste Zielübersetzung.
 * Einträge mit leerer Zielübersetzung werden von den Providern übersprungen
 * ({@see TranslateOptions::glossaryEntries()}).
 */
final class GlossaryEntry {
    public function __construct(
        public readonly string $term,
        public readonly string $translation,
    ) {}

    /** @return array{term: string, translation: string} */
    public function toArray(): array {
        return ['term' => $this->term, 'translation' => $this->translation];
    }
}
