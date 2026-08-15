<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TranslationRegistryTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use TranslationToolkit\Providers\DeepLProvider;
use TranslationToolkit\{TranslationRegistry, TranslationService};

final class TranslationRegistryTest extends TestCase {
    protected function tearDown(): void {
        TranslationRegistry::reset();
    }

    public function test_registry_is_empty_by_default(): void {
        $this->assertFalse(TranslationRegistry::hasService());
        $this->assertNull(TranslationRegistry::getService());
        $this->assertFalse(TranslationRegistry::isAvailable());
    }

    public function test_set_and_reset_service(): void {
        $service = new TranslationService(new DeepLProvider('key'));
        TranslationRegistry::setService($service);

        $this->assertTrue(TranslationRegistry::hasService());
        $this->assertSame($service, TranslationRegistry::getService());
        $this->assertTrue(TranslationRegistry::isAvailable());

        TranslationRegistry::reset();
        $this->assertFalse(TranslationRegistry::hasService());
    }

    public function test_is_available_respects_provider(): void {
        TranslationRegistry::setService(new TranslationService(new DeepLProvider('')));

        $this->assertTrue(TranslationRegistry::hasService());
        $this->assertFalse(TranslationRegistry::isAvailable(), 'Ohne API-Key darf isAvailable() false liefern');
    }
}
