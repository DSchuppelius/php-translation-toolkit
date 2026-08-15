<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TranslationProviderInterface.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Contracts\Interfaces;

use TranslationToolkit\Entities\{TranslateOptions, TranslationResult};
use TranslationToolkit\Exceptions\TranslationException;

/**
 * Port für Übersetzungs-Provider (DeepL, Azure Translator, LibreTranslate, ...).
 */
interface TranslationProviderInterface {
    /**
     * Eindeutiger Provider-Name (z.B. "deepl"). Geht in den Cache-Hash ein.
     */
    public function getName(): string;

    /**
     * Ist der Provider einsatzbereit (z.B. API-Key konfiguriert)?
     */
    public function isAvailable(): bool;

    /**
     * Übersetzt einen Text in die Zielsprache.
     *
     * @param string $text Der zu übersetzende Text
     * @param string $targetLang Zielsprache (ISO 639-1, z.B. "de")
     * @param string|null $sourceLang Quellsprache oder null für automatische Erkennung
     * @param TranslateOptions|null $options Format, Förmlichkeit, Glossar (null = Defaults)
     * @throws TranslationException bei Konfigurations-, Transport- oder API-Fehlern
     */
    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        ?TranslateOptions $options = null,
    ): TranslationResult;

    /**
     * Erzwingt der Provider Glossarbegriffe deterministisch (natives Glossar,
     * Dictionary-Markup, Token-Maskierung) oder gar nicht? Das Ergebnis führt
     * die tatsächliche Wirkung je Aufruf
     * ({@see TranslationResult::$deterministicTerminology}).
     */
    public function supportsGlossary(): bool;

    /**
     * Billigster echter Aufruf zur Verbindungsprüfung (Key, Basis-URL,
     * Erreichbarkeit).
     *
     * @throws TranslationException wenn die Verbindung nicht nutzbar ist
     */
    public function preflight(): void;

    /**
     * Unterstützte Zielsprachen (ISO 639-1, kleingeschrieben).
     *
     * @return list<string>
     * @throws TranslationException wenn die Liste beim Anbieter erfragt wird und der Aufruf scheitert
     */
    public function getSupportedTargetLanguages(): array;
}
