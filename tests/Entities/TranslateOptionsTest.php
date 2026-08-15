<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TranslateOptionsTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Entities;

use PHPUnit\Framework\TestCase;
use TranslationToolkit\Entities\{GlossaryEntry, TranslateOptions};
use TranslationToolkit\Enums\{Formality, TextFormat};

final class TranslateOptionsTest extends TestCase {
    public function test_defaults_are_recognized(): void {
        $this->assertTrue((new TranslateOptions)->isDefault());
        $this->assertFalse((new TranslateOptions(format: TextFormat::Html))->isDefault());
        $this->assertFalse((new TranslateOptions(formality: Formality::Less))->isDefault());
    }

    public function test_entries_without_translation_are_dropped(): void {
        $options = new TranslateOptions(glossary: [
            new GlossaryEntry('a', 'A'),
            new GlossaryEntry('b', ''),
            new GlossaryEntry('c', '   '),
        ]);

        $entries = $options->glossaryEntries();

        $this->assertCount(1, $entries);
        $this->assertSame('a', $entries[0]->term);
        $this->assertTrue($options->hasGlossary());
    }

    public function test_glossary_with_only_empty_translations_counts_as_default(): void {
        $options = new TranslateOptions(glossary: [new GlossaryEntry('a', '')]);

        $this->assertFalse($options->hasGlossary());
        $this->assertTrue($options->isDefault(), 'Wirkungslose Einträge dürfen den Cache nicht spalten');
    }

    public function test_fingerprint_reflects_effective_values(): void {
        $a = new TranslateOptions(glossary: [new GlossaryEntry('a', 'A')]);
        $b = new TranslateOptions(glossary: [new GlossaryEntry('a', 'A'), new GlossaryEntry('b', '')]);
        $c = new TranslateOptions(glossary: [new GlossaryEntry('a', 'B')]);

        $this->assertSame($a->fingerprint(), $b->fingerprint(), 'Leere Einträge ändern das Ergebnis nicht');
        $this->assertNotSame($a->fingerprint(), $c->fingerprint());
    }
}
