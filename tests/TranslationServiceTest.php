<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TranslationServiceTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use TranslationToolkit\Caches\ArrayTranslationCache;
use TranslationToolkit\Contracts\Interfaces\{TranslationProviderInterface, TranslationUsageListenerInterface};
use TranslationToolkit\Entities\TranslationResult;
use TranslationToolkit\TranslationService;

final class TranslationServiceTest extends TestCase {
    private int $providerCalls = 0;

    private function createProvider(string $translated = 'Hallo Welt'): TranslationProviderInterface {
        $test = $this;

        return new class($test, $translated) implements TranslationProviderInterface {
            public function __construct(private readonly TranslationServiceTest $test, private readonly string $translated) {}

            public function getName(): string {
                return 'fake';
            }

            public function isAvailable(): bool {
                return true;
            }

            public function translate(string $text, string $targetLang, ?string $sourceLang = null): TranslationResult {
                $this->test->countProviderCall();

                return new TranslationResult(
                    text: $this->translated,
                    sourceText: $text,
                    targetLang: $targetLang,
                    detectedSourceLang: 'ru',
                    provider: 'fake',
                    charCount: mb_strlen($text),
                    fromCache: false,
                );
            }

            public function getSupportedTargetLanguages(): array {
                return ['de', 'en'];
            }
        };
    }

    public function countProviderCall(): void {
        $this->providerCalls++;
    }

    public function test_translate_uses_provider_and_fills_cache(): void {
        $cache = new ArrayTranslationCache;
        $service = new TranslationService($this->createProvider(), $cache);

        $result = $service->translate('Привет мир, как дела?');

        $this->assertSame('Hallo Welt', $result->text);
        $this->assertFalse($result->fromCache);
        $this->assertSame('de', $result->targetLang);
        $this->assertSame(1, $this->providerCalls);
        $this->assertSame(1, $cache->count());
    }

    public function test_second_translate_is_served_from_cache(): void {
        $cache = new ArrayTranslationCache;
        $service = new TranslationService($this->createProvider(), $cache);

        $service->translate('Привет мир, как дела?');
        $result = $service->translate('Привет мир, как дела?');

        $this->assertTrue($result->fromCache);
        $this->assertSame('Hallo Welt', $result->text);
        $this->assertSame(1, $this->providerCalls, 'Provider darf beim Cache-Hit nicht erneut aufgerufen werden');
    }

    public function test_empty_text_skips_provider(): void {
        $service = new TranslationService($this->createProvider());

        $result = $service->translate('   ');

        $this->assertSame('   ', $result->text);
        $this->assertSame(0, $result->charCount);
        $this->assertSame(0, $this->providerCalls);
    }

    public function test_usage_listener_receives_api_and_cache_results(): void {
        $recorded = [];
        $listener = new class($recorded) implements TranslationUsageListenerInterface {
            /** @param list<TranslationResult> $recorded */
            public function __construct(public array &$recorded) {}

            public function onTranslation(TranslationResult $result): void {
                $this->recorded[] = $result;
            }
        };

        $service = new TranslationService($this->createProvider(), new ArrayTranslationCache, $listener);
        $service->translate('Привет мир, как дела?');
        $service->translate('Привет мир, как дела?');

        $this->assertCount(2, $listener->recorded);
        $this->assertFalse($listener->recorded[0]->fromCache);
        $this->assertTrue($listener->recorded[1]->fromCache);
    }

    public function test_needs_translation_heuristic(): void {
        // Kurzer Text: keine Übersetzung (v1: strlen > 12)
        $this->assertFalse(TranslationService::needsTranslation('Привет'));
        // Langer kyrillischer Text: Übersetzung
        $this->assertTrue(TranslationService::needsTranslation('Привет мир, как дела?'));
        // Langer lateinischer Text: keine Übersetzung
        $this->assertFalse(TranslationService::needsTranslation('SEPA Überweisung Miete Januar'));
        // Han-Zeichen mit angepasster Mindestlänge
        $this->assertTrue(TranslationService::needsTranslation('風間 圭介', 4));
    }

    public function test_cache_hash_is_stable_and_distinct(): void {
        $a = TranslationService::cacheHash('Text', null, 'de', 'deepl');
        $b = TranslationService::cacheHash('Text', null, 'de', 'deepl');
        $c = TranslationService::cacheHash('Text', null, 'en', 'deepl');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }
}
