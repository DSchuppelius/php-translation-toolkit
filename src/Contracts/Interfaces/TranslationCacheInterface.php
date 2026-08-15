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

/**
 * Port für den Übersetzungs-Cache.
 *
 * Der Cache ist bewusst global (nicht pro Mandant), damit identische Texte
 * nur einmal Übersetzungskosten verursachen (ADR-0010). Der Hash wird vom
 * TranslationService berechnet: SHA-256(text|source|target|provider).
 */
interface TranslationCacheInterface {
    /**
     * Liefert die gecachte Übersetzung oder null bei Cache-Miss.
     */
    public function get(string $hash): ?string;

    /**
     * Legt eine Übersetzung im Cache ab.
     *
     * Die Zusatzfelder erlauben persistenten Implementierungen (DB) die
     * Ablage von Kontext und Statistik (char_count, use_count, ...).
     */
    public function set(
        string $hash,
        string $sourceText,
        ?string $sourceLang,
        string $targetLang,
        string $translatedText,
        string $provider,
    ): void;
}
