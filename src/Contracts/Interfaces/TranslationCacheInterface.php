<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TranslationCacheInterface.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Contracts\Interfaces;

use TranslationToolkit\Entities\{CachedTranslation, TranslationResult};

/**
 * Port für den Übersetzungs-Cache.
 *
 * Der Cache ist bewusst global (nicht pro Mandant), damit identische Texte
 * nur einmal Übersetzungskosten verursachen (ADR-0010). Der Hash wird vom
 * TranslationService berechnet: SHA-256(text|source|target|provider) plus
 * Options-Fingerprint bei Nicht-Default-Optionen.
 */
interface TranslationCacheInterface {
    /**
     * Liefert den Cache-Treffer samt Metadaten des Ursprungslaufs oder null.
     */
    public function get(string $hash): ?CachedTranslation;

    /**
     * Legt ein Übersetzungsergebnis im Cache ab. $sourceLang ist die
     * ANGEFRAGTE Quellsprache (null = automatische Erkennung); die erkannte
     * Sprache steht im Ergebnis.
     */
    public function set(string $hash, ?string $sourceLang, TranslationResult $result): void;
}
