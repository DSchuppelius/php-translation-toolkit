<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CompositeProviderTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Providers;

use PHPUnit\Framework\TestCase;
use TranslationToolkit\Contracts\Interfaces\TranslationProviderInterface;
use TranslationToolkit\Entities\{TranslateOptions, TranslationResult};
use TranslationToolkit\Exceptions\TranslationException;
use TranslationToolkit\Providers\CompositeProvider;

final class CompositeProviderTest extends TestCase {
    private function fakeProvider(string $name, bool $available = true, bool $failing = false): TranslationProviderInterface {
        return new class($name, $available, $failing) implements TranslationProviderInterface {
            public int $calls = 0;

            public function __construct(
                private readonly string $name,
                private readonly bool $available,
                private readonly bool $failing,
            ) {}

            public function getName(): string {
                return $this->name;
            }

            public function isAvailable(): bool {
                return $this->available;
            }

            public function supportsGlossary(): bool {
                return true;
            }

            public function preflight(): void {
                if ($this->failing) {
                    throw new TranslationException($this->name . ' nicht erreichbar');
                }
            }

            public function translate(
                string $text,
                string $targetLang,
                ?string $sourceLang = null,
                ?TranslateOptions $options = null,
            ): TranslationResult {
                $this->calls++;
                if ($this->failing) {
                    throw new TranslationException($this->name . ' fehlgeschlagen');
                }

                return new TranslationResult($this->name . ':' . $text, $text, $targetLang, null, $this->name, mb_strlen($text));
            }

            public function translateBatch(
                array $texts,
                string $targetLang,
                ?string $sourceLang = null,
                ?TranslateOptions $options = null,
            ): array {
                return array_map(
                    fn (string $text): TranslationResult => $this->translate($text, $targetLang, $sourceLang, $options),
                    $texts
                );
            }

            public function getSupportedTargetLanguages(): array {
                return $this->name === 'primary' ? ['de', 'en'] : ['de', 'fr'];
            }
        };
    }

    public function test_requires_at_least_one_provider(): void {
        $this->expectException(TranslationException::class);
        new CompositeProvider;
    }

    public function test_name_is_stable_chain_of_all_providers(): void {
        $composite = new CompositeProvider($this->fakeProvider('primary'), $this->fakeProvider('secondary'));

        $this->assertSame('primary+secondary', $composite->getName());
    }

    public function test_first_available_provider_translates(): void {
        $composite = new CompositeProvider($this->fakeProvider('primary'), $this->fakeProvider('secondary'));

        $result = $composite->translate('Text', 'de');

        $this->assertSame('primary:Text', $result->text);
        $this->assertSame('primary', $result->provider, 'Das Ergebnis trägt den tatsächlichen Provider');
    }

    public function test_falls_back_when_primary_fails(): void {
        $composite = new CompositeProvider(
            $this->fakeProvider('primary', failing: true),
            $this->fakeProvider('secondary'),
        );

        $result = $composite->translate('Text', 'de');

        $this->assertSame('secondary:Text', $result->text);
    }

    public function test_unavailable_providers_are_skipped(): void {
        $composite = new CompositeProvider(
            $this->fakeProvider('primary', available: false),
            $this->fakeProvider('secondary'),
        );

        $this->assertTrue($composite->isAvailable());
        $this->assertSame('secondary:Text', $composite->translate('Text', 'de')->text);
    }

    public function test_throws_last_error_when_all_fail(): void {
        $composite = new CompositeProvider(
            $this->fakeProvider('primary', failing: true),
            $this->fakeProvider('secondary', failing: true),
        );

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('secondary fehlgeschlagen');
        $composite->translate('Text', 'de');
    }

    public function test_batch_falls_back_too(): void {
        $composite = new CompositeProvider(
            $this->fakeProvider('primary', failing: true),
            $this->fakeProvider('secondary'),
        );

        $results = $composite->translateBatch(['A', 'B'], 'de');

        $this->assertSame('secondary:A', $results[0]->text);
        $this->assertSame('secondary:B', $results[1]->text);
    }

    public function test_supported_languages_are_the_union(): void {
        $composite = new CompositeProvider($this->fakeProvider('primary'), $this->fakeProvider('secondary'));

        $this->assertSame(['de', 'en', 'fr'], $composite->getSupportedTargetLanguages());
    }
}
