<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : LibreTranslateProviderTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Providers;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordsHttp;
use TranslationToolkit\Entities\{GlossaryEntry, TranslateOptions};
use TranslationToolkit\Enums\TextFormat;
use TranslationToolkit\Exceptions\TranslationException;
use TranslationToolkit\Providers\LibreTranslateProvider;

final class LibreTranslateProviderTest extends TestCase {
    use RecordsHttp;

    /**
     * @param list<Response> $responses
     */
    private function createProvider(array $responses, ?string $apiKey = null): LibreTranslateProvider {
        return new LibreTranslateProvider('https://lt.example.test', $apiKey, null, $this->mockClient($responses));
    }

    public function test_default_base_url_is_localhost(): void {
        $this->assertSame('http://localhost:5000', (new LibreTranslateProvider)->getBaseUrl());
    }

    public function test_is_available_without_api_key(): void {
        // Öffentliche Instanzen laufen ohne Schlüssel.
        $this->assertTrue((new LibreTranslateProvider)->isAvailable());
    }

    public function test_translate_sends_payload_and_parses_response(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translatedText' => 'Hallo Welt', 'detectedLanguage' => ['language' => 'RU', 'confidence' => 90]]),
        ]);

        $result = $provider->translate('Привет мир', 'de');

        $request = $this->requestAt(0);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/translate', $request->getUri()->getPath());

        $body = $this->bodyAt(0);
        $this->assertSame('Привет мир', $body['q']);
        $this->assertSame('auto', $body['source']);
        $this->assertSame('de', $body['target']);
        $this->assertSame('text', $body['format']);
        $this->assertArrayNotHasKey('api_key', $body);

        $this->assertSame('Hallo Welt', $result->text);
        $this->assertSame('ru', $result->detectedSourceLang);
        $this->assertSame('libretranslate', $result->provider);
        $this->assertFalse($result->deterministicTerminology);
    }

    public function test_api_key_and_source_and_html_reach_payload(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translatedText' => '<p>Hallo</p>']),
        ], apiKey: 'secret');

        $provider->translate('<p>Hello</p>', 'de', 'en', new TranslateOptions(format: TextFormat::Html));

        $body = $this->bodyAt(0);
        $this->assertSame('secret', $body['api_key']);
        $this->assertSame('en', $body['source']);
        $this->assertSame('html', $body['format']);
    }

    public function test_glossary_terms_are_masked_and_restored(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translatedText' => 'Das XLTTERM0X ist fällig']),
        ]);

        $result = $provider->translate('The invoice date is due', 'de', 'en', new TranslateOptions(
            glossary: [new GlossaryEntry('invoice date', 'Belegdatum')]
        ));

        // Der Begriff geht maskiert raus …
        $this->assertSame('The XLTTERM0X is due', $this->bodyAt(0)['q']);
        // … und kommt als feste Zielübersetzung zurück.
        $this->assertSame('Das Belegdatum ist fällig', $result->text);
        $this->assertTrue($result->deterministicTerminology);
    }

    public function test_glossary_term_not_present_in_text_is_not_forced(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translatedText' => 'Etwas anderes']),
        ]);

        $result = $provider->translate('Something else', 'de', 'en', new TranslateOptions(
            glossary: [new GlossaryEntry('invoice date', 'Belegdatum')]
        ));

        $this->assertSame('Something else', $this->bodyAt(0)['q']);
        $this->assertSame('Etwas anderes', $result->text);
        $this->assertFalse($result->deterministicTerminology);
    }

    public function test_malformed_response_throws(): void {
        $provider = $this->createProvider([$this->jsonResponse(['error' => 'nope'])]);

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('translatedText fehlt');
        $provider->translate('Text', 'de');
    }

    public function test_rate_limit_is_translated(): void {
        $provider = $this->createProvider([new Response(429)]);
        // Ein einziger Versuch, sonst frisst der Wiederholungspfad die Mock-Antwort.
        $provider->setMaxRetries(1);

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('Rate-Limit');
        $provider->translate('Text', 'de');
    }

    public function test_server_error_is_retried_before_failing(): void {
        $provider = $this->createProvider([
            new Response(503),
            $this->jsonResponse(['translatedText' => 'Hallo Welt']),
        ]);
        $provider->setBaseRetryDelay(0);

        $result = $provider->translate('Hello world', 'de');

        $this->assertSame('Hallo Welt', $result->text);
        $this->assertCount(2, $this->history, 'Der erste Versuch muss wiederholt worden sein');
    }

    public function test_supported_target_languages_are_fetched_once(): void {
        $provider = $this->createProvider([
            $this->jsonResponse([
                ['code' => 'EN', 'name' => 'English', 'targets' => ['de']],
                ['code' => 'de', 'name' => 'German', 'targets' => ['en']],
            ]),
        ]);

        $first = $provider->getSupportedTargetLanguages();
        $second = $provider->getSupportedTargetLanguages();

        $this->assertSame(['de', 'en'], $first);
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->history);
    }

    public function test_preflight_calls_languages(): void {
        $provider = $this->createProvider([$this->jsonResponse([])]);

        $provider->preflight();

        $this->assertSame('/languages', $this->requestAt(0)->getUri()->getPath());
    }
}
