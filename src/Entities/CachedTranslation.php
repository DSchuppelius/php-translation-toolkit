<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CachedTranslation.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Entities;

/**
 * Cache-Treffer mit den Metadaten des Ursprungslaufs — damit ein Hit ehrlich
 * melden kann, ob damals Terminologie erzwungen und welche Quellsprache
 * erkannt wurde.
 */
final class CachedTranslation {
    public function __construct(
        /** Der übersetzte Text */
        public readonly string $text,
        /** Beim Ursprungslauf erkannte Quellsprache oder null */
        public readonly ?string $detectedSourceLang = null,
        /** Wurden beim Ursprungslauf Glossarbegriffe deterministisch erzwungen? */
        public readonly bool $deterministicTerminology = false,
    ) {}
}
