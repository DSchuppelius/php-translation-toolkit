<?php
/*
 * Created on   : Mon Sep 01 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TransliterationProvider.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Providers;

use ERRORToolkit\Traits\ErrorLog;
use TranslationToolkit\Contracts\Interfaces\TranslationProviderInterface;
use TranslationToolkit\Entities\{TranslateOptions, TranslationResult};
use TranslationToolkit\Exceptions\TranslationException;
use Transliterator;

/**
 * Romanisiert nicht-lateinische Schrift, statt zu übersetzen ("АРХЦЕНТЪР-А ООД"
 * → "ARHCENT'R-A OOD", "カザマ ケイスケ" → "kazama keisuke").
 *
 * Kein Übersetzungsdienst, sondern der verlässliche Boden darunter: offline,
 * deterministisch, ohne Kosten. Gedacht als letztes Glied einer
 * {@see CompositeProvider}-Kette oder als eigenständiger Provider, wenn eine
 * Zielschrift genügt und keine Zielsprache verlangt ist.
 *
 * Der Anlass: Ausgabeformate mit begrenztem Zeichensatz. DATEV-ASCII kann
 * kyrillische Zeichen nicht abbilden und macht sonst Fragezeichen daraus –
 * eine romanisierte Form ist dort mehr wert als eine unlesbare.
 *
 * Grenzen, die bewusst so sind:
 * - Die Zielsprache wird ignoriert; das Ergebnis ist eine Umschrift, keine
 *   Übersetzung. Es trägt sie dennoch im {@see TranslationResult}, damit der
 *   Aufrufer nichts unterscheiden muss.
 * - Lateinischer Text bleibt unverändert – auch das ist ein Ergebnis.
 * - Ohne die intl-Erweiterung ist der Provider nicht verfügbar.
 */
final class TransliterationProvider implements TranslationProviderInterface {
    use ErrorLog;

    /**
     * ICU-Kette: erst romanisieren, dann Diakritika entfernen, dann auf ASCII.
     *
     * Das Entfernen der Nonspacing Marks ist der Punkt, an dem sich die Kette
     * bewährt: "Ъ" wird sonst zu "Ŭ", das ein ASCII-Ziel ebenso wenig kennt.
     */
    private const RULE = 'Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII';

    private ?Transliterator $transliterator = null;

    public function getName(): string {
        return 'transliteration';
    }

    public function isAvailable(): bool {
        return class_exists(Transliterator::class) && $this->transliterator() !== null;
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        ?TranslateOptions $options = null,
    ): TranslationResult {
        $transliterator = $this->transliterator();
        if ($transliterator === null) {
            throw new TranslationException('Transliteration nicht verfügbar: intl-Erweiterung fehlt oder die ICU-Regel ist unbekannt');
        }

        $romanisiert = $text;
        // Reiner Latin-1-Text bliebe ohnehin unverändert; der Test spart den ICU-Aufruf
        // und hält Umlaute fern, die Latin-ASCII sonst zu "ae"/"oe" abbauen würde.
        if (preg_match('/[^\p{Latin}\p{Common}\p{Inherited}]/u', $text) === 1) {
            $ergebnis = $transliterator->transliterate($text);
            if (is_string($ergebnis) && $ergebnis !== '') {
                $romanisiert = $ergebnis;
            }
        }

        return new TranslationResult(
            text: $romanisiert,
            sourceText: $text,
            targetLang: strtolower($targetLang),
            detectedSourceLang: null,
            provider: $this->getName(),
            charCount: mb_strlen($text),
        );
    }

    /**
     * {@inheritDoc}
     *
     * Ohne API gibt es nichts zu bündeln – die Schleife ist die ganze Optimierung.
     */
    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        ?TranslateOptions $options = null,
    ): array {
        return array_map(
            fn (string $text): TranslationResult => $this->translate($text, $targetLang, $sourceLang, $options),
            $texts,
        );
    }

    /** Eine Umschrift kennt keine Terminologie. */
    public function supportsGlossary(): bool {
        return false;
    }

    public function preflight(): void {
        if (!$this->isAvailable()) {
            throw new TranslationException('Transliteration nicht verfügbar: die PHP-Erweiterung intl fehlt');
        }
    }

    /**
     * {@inheritDoc}
     *
     * Die Umschrift richtet sich nach der Quell-, nicht nach der Zielsprache –
     * deshalb ist jede Zielsprache gleich gut bedient.
     *
     * @return list<string>
     */
    public function getSupportedTargetLanguages(): array {
        return [];
    }

    private function transliterator(): ?Transliterator {
        if ($this->transliterator instanceof Transliterator) {
            return $this->transliterator;
        }
        if (!class_exists(Transliterator::class)) {
            return null;
        }

        return $this->transliterator = Transliterator::create(self::RULE);
    }
}
