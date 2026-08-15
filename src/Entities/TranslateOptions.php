<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TranslateOptions.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Entities;

use TranslationToolkit\Enums\{Formality, TextFormat};

/**
 * Optionale Übersetzungs-Parameter: Format, Förmlichkeit und zu erzwingende
 * Glossarbegriffe. Ohne Optionen (bzw. mit Defaults) verhält sich alles wie
 * vor v0.3 — auch der Cache-Schlüssel bleibt dann stabil
 * ({@see \TranslationToolkit\TranslationService::cacheHash()}).
 */
final class TranslateOptions {
    /**
     * @param list<GlossaryEntry> $glossary
     */
    public function __construct(
        public readonly TextFormat $format = TextFormat::Text,
        public readonly Formality $formality = Formality::Default,
        public readonly array $glossary = [],
    ) {}

    /**
     * Wirksame Glossareinträge: nur solche mit nicht-leerer Zielübersetzung.
     *
     * @return list<GlossaryEntry>
     */
    public function glossaryEntries(): array {
        return array_values(array_filter(
            $this->glossary,
            static fn (GlossaryEntry $e): bool => trim($e->translation) !== ''
        ));
    }

    public function hasGlossary(): bool {
        return $this->glossaryEntries() !== [];
    }

    /**
     * Entsprechen alle Werte den Defaults? Dann fließen die Optionen nicht in
     * den Cache-Schlüssel ein (Hash-Kompatibilität zu v0.2-Einträgen).
     */
    public function isDefault(): bool {
        return $this->format === TextFormat::Text
            && $this->formality === Formality::Default
            && !$this->hasGlossary();
    }

    /**
     * Kanonischer Fingerprint für den Cache-Schlüssel: Glossar, Format und
     * Förmlichkeit verändern das Übersetzungsergebnis.
     */
    public function fingerprint(): string {
        return hash('sha256', (string) json_encode([
            'format' => $this->format->value,
            'formality' => $this->formality->value,
            'glossary' => array_map(
                static fn (GlossaryEntry $e): array => $e->toArray(),
                $this->glossaryEntries()
            ),
        ], JSON_UNESCAPED_UNICODE));
    }
}
