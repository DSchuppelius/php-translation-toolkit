<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DeepLProviderTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Providers;

use PHPUnit\Framework\TestCase;
use TranslationToolkit\Exceptions\TranslationException;
use TranslationToolkit\Providers\DeepLProvider;

/**
 * Testbarer Provider: request() liefert vorbereitete Antworten statt HTTP.
 */
final class FakeDeepLProvider extends DeepLProvider {
    public string $lastMethod = '';
    public string $lastPath = '';
    /** @var array<string, mixed> */
    public array $lastPayload = [];
    /** @var array<string, mixed> */
    public array $response = [];

    protected function request(string $method, string $path, array $payload): array {
        $this->lastMethod = $method;
        $this->lastPath = $path;
        $this->lastPayload = $payload;

        return $this->response;
    }
}

final class DeepLProviderTest extends TestCase {
    public function test_free_key_selects_free_endpoint(): void {
        $provider = new DeepLProvider('abc123:fx');
        $this->assertSame('https://api-free.deepl.com', $provider->getBaseUrl());
    }

    public function test_pro_key_selects_pro_endpoint(): void {
        $provider = new DeepLProvider('abc123');
        $this->assertSame('https://api.deepl.com', $provider->getBaseUrl());
    }

    public function test_explicit_base_url_wins(): void {
        $provider = new DeepLProvider('abc123:fx', 'https://proxy.example.test/');
        $this->assertSame('https://proxy.example.test', $provider->getBaseUrl());
    }

    public function test_is_available_requires_api_key(): void {
        $this->assertFalse((new DeepLProvider(''))->isAvailable());
        $this->assertTrue((new DeepLProvider('key'))->isAvailable());
    }

    public function test_translate_without_key_throws(): void {
        $this->expectException(TranslationException::class);
        (new DeepLProvider(''))->translate('Text', 'de');
    }

    public function test_translate_parses_response(): void {
        $provider = new FakeDeepLProvider('key');
        $provider->response = [
            'translations' => [
                ['detected_source_language' => 'RU', 'text' => 'Hallo Welt'],
            ],
        ];

        $result = $provider->translate('Привет мир', 'de');

        $this->assertSame('POST', $provider->lastMethod);
        $this->assertSame('/v2/translate', $provider->lastPath);
        $this->assertSame(['Привет мир'], $provider->lastPayload['text']);
        $this->assertSame('DE', $provider->lastPayload['target_lang']);
        $this->assertArrayNotHasKey('source_lang', $provider->lastPayload);

        $this->assertSame('Hallo Welt', $result->text);
        $this->assertSame('ru', $result->detectedSourceLang);
        $this->assertSame('de', $result->targetLang);
        $this->assertSame('deepl', $result->provider);
        $this->assertSame(mb_strlen('Привет мир'), $result->charCount);
        $this->assertFalse($result->fromCache);
    }

    public function test_translate_passes_source_lang(): void {
        $provider = new FakeDeepLProvider('key');
        $provider->response = ['translations' => [['text' => 'Hello']]];

        $provider->translate('Hallo', 'en', 'de');

        $this->assertSame('DE', $provider->lastPayload['source_lang']);
    }

    public function test_translate_with_malformed_response_throws(): void {
        $provider = new FakeDeepLProvider('key');
        $provider->response = ['translations' => []];

        $this->expectException(TranslationException::class);
        $provider->translate('Text', 'de');
    }

    public function test_get_usage_maps_response(): void {
        $provider = new FakeDeepLProvider('key');
        $provider->response = ['character_count' => 12450, 'character_limit' => 500000];

        $usage = $provider->getUsage();

        $this->assertSame('GET', $provider->lastMethod);
        $this->assertSame('/v2/usage', $provider->lastPath);
        $this->assertSame(12450, $usage['characterCount']);
        $this->assertSame(500000, $usage['characterLimit']);
    }
}
