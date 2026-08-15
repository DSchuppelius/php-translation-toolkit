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

use GuzzleHttp\{Client as HttpClient, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TranslationToolkit\Exceptions\TranslationException;
use TranslationToolkit\Providers\DeepLProvider;

final class DeepLProviderTest extends TestCase {
    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $history;

    /**
     * Aufgezeichneter Request aus der Guzzle-History.
     */
    private function requestAt(int $index): RequestInterface {
        $request = $this->history[$index]['request'] ?? null;
        if (!$request instanceof RequestInterface) {
            $this->fail("Kein Request an Position {$index} aufgezeichnet");
        }

        return $request;
    }

    /**
     * Provider mit vorbereiteten Antworten (Guzzle MockHandler) — testet den
     * echten Request-Pfad des api-toolkit inklusive Auth-Header.
     *
     * @param list<Response> $responses
     */
    private function createProvider(array $responses, string $apiKey = 'key'): DeepLProvider {
        $history = new \ArrayObject;
        $this->history = $history;
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new DeepLProvider($apiKey, null, null, new HttpClient(['handler' => $stack]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data, int $status = 200): Response {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

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

    public function test_translate_parses_response_and_sends_auth_header(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translations' => [['detected_source_language' => 'RU', 'text' => 'Hallo Welt']]]),
        ]);

        $result = $provider->translate('Привет мир', 'de');

        $this->assertCount(1, $this->history);
        $request = $this->requestAt(0);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v2/translate', $request->getUri()->getPath());
        $this->assertSame('DeepL-Auth-Key key', $request->getHeaderLine('Authorization'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(['Привет мир'], $body['text']);
        $this->assertSame('DE', $body['target_lang']);
        $this->assertArrayNotHasKey('source_lang', $body);

        $this->assertSame('Hallo Welt', $result->text);
        $this->assertSame('ru', $result->detectedSourceLang);
        $this->assertSame('de', $result->targetLang);
        $this->assertSame('deepl', $result->provider);
        $this->assertSame(mb_strlen('Привет мир'), $result->charCount);
        $this->assertFalse($result->fromCache);
    }

    public function test_translate_passes_source_lang(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translations' => [['text' => 'Hello']]]),
        ]);

        $provider->translate('Hallo', 'en', 'de');

        $body = json_decode((string) $this->requestAt(0)->getBody(), true);
        $this->assertSame('DE', $body['source_lang']);
    }

    public function test_translate_with_malformed_response_throws(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translations' => []]),
        ]);

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('translations[0].text fehlt');
        $provider->translate('Text', 'de');
    }

    public function test_auth_error_is_translated(): void {
        $provider = $this->createProvider([new Response(403)]);

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('HTTP 403');
        $provider->translate('Text', 'de');
    }

    public function test_quota_exceeded_is_translated(): void {
        $provider = $this->createProvider([new Response(456)]);

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('Zeichenkontingent');
        $provider->translate('Text', 'de');
    }

    public function test_get_usage_maps_response(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['character_count' => 12450, 'character_limit' => 500000]),
        ]);

        $usage = $provider->getUsage();

        $request = $this->requestAt(0);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v2/usage', $request->getUri()->getPath());
        $this->assertSame(12450, $usage['characterCount']);
        $this->assertSame(500000, $usage['characterLimit']);
    }
}
