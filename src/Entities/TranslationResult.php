<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TranslationResult.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Entities;

/**
 * Ergebnis einer Übersetzung.
 */
final class TranslationResult {
    public function __construct(
        /** Der übersetzte Text */
        public readonly string $text,
        /** Der ursprüngliche Quelltext */
        public readonly string $sourceText,
        /** Zielsprache (ISO 639-1, kleingeschrieben) */
        public readonly string $targetLang,
        /** Erkannte Quellsprache oder null, wenn unbekannt (z.B. Cache-Hit) */
        public readonly ?string $detectedSourceLang,
        /** Provider-Name (z.B. "deepl") */
        public readonly string $provider,
        /** Zeichenanzahl des Quelltexts (Berechnungsgrundlage) */
        public readonly int $charCount,
        /** true, wenn die Übersetzung aus dem Cache bedient wurde */
        public readonly bool $fromCache = false,
        /**
         * Wurden die übergebenen Glossarbegriffe bei DIESEM Aufruf
         * deterministisch erzwungen? false auch dann, wenn der Provider
         * Glossare grundsätzlich beherrscht, aber keine Begriffe übergeben
         * wurden oder Voraussetzungen fehlten (z.B. DeepL ohne Quellsprache) —
         * und stets bei Cache-Treffern, denn dort findet keine Übersetzung
         * statt (der Cache hält nur den Text, nicht die Erzwingungs-Historie).
         */
        public readonly bool $deterministicTerminology = false,
    ) {}
}
