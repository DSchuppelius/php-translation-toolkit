<?php
/*
 * Created on   : Mon Sep 01 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TransliterationProviderTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Providers;

use PHPUnit\Framework\TestCase;
use TranslationToolkit\Providers\TransliterationProvider;

final class TransliterationProviderTest extends TestCase {
    private TransliterationProvider $provider;

    protected function setUp(): void {
        if (!class_exists(\Transliterator::class)) {
            self::markTestSkipped('Die PHP-Erweiterung intl fehlt.');
        }
        $this->provider = new TransliterationProvider;
    }

    public function test_romanizes_cyrillic(): void {
        // Der Anlass: DATEV-ASCII kann Kyrillisch nicht abbilden und schriebe sonst
        // Fragezeichen — der Empfänger wäre nicht mehr erkennbar.
        $result = $this->provider->translate('АРХЦЕНТЪР-А ООД', 'de');

        self::assertSame('ARHCENT"R-A OOD', $result->text);
        self::assertSame('АРХЦЕНТЪР-А ООД', $result->sourceText);
        self::assertSame('transliteration', $result->provider);
    }

    public function test_romanizes_katakana(): void {
        self::assertSame('kazama keisuke', $this->provider->translate('カザマ ケイスケ', 'de')->text);
    }

    public function test_removes_diacritics_that_ascii_targets_cannot_hold(): void {
        // Ohne "[:Nonspacing Mark:] Remove" bliebe aus dem bulgarischen "Ъ" ein "Ŭ" übrig.
        self::assertMatchesRegularExpression('/^[\x00-\x7F]+$/', $this->provider->translate('Енергия за м.04', 'de')->text);
    }

    public function test_leaves_latin_text_untouched(): void {
        // Auch deutsche Umlaute: Latin-ASCII würde sie sonst zu "ae"/"oe" abbauen.
        self::assertSame('Grüße aus München', $this->provider->translate('Grüße aus München', 'de')->text);
        self::assertSame('Ecovis Accounting', $this->provider->translate('Ecovis Accounting', 'de')->text);
    }

    public function test_batch_keeps_input_order(): void {
        $results = $this->provider->translateBatch(['Вода', 'Ecovis', 'カザマ'], 'de');

        self::assertSame(['Voda', 'Ecovis', 'kazama'], array_map(static fn ($r): string => $r->text, $results));
    }

    public function test_is_available_and_has_no_glossary(): void {
        self::assertTrue($this->provider->isAvailable(), 'Ohne API ist der Provider immer einsatzbereit');
        self::assertFalse($this->provider->supportsGlossary(), 'Eine Umschrift kennt keine Terminologie');
    }
}
