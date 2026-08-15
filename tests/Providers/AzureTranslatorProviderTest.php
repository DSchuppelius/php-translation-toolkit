<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : AzureTranslatorProviderTest.php
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
use TranslationToolkit\Providers\AzureTranslatorProvider;

final class AzureTranslatorProviderTest extends TestCase {
    use RecordsHttp;

    /**
     * @param list<Response> $responses
     */
    private function createProvider(array $responses, string $apiKey = 'key', ?string $region = null): AzureTranslatorProvider {
        return new AzureTranslatorProvider($apiKey, $region, null, null, $this->mockClient($responses));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function translationResponse(string $text = 'Hallo Welt', array $extra = []): Response {
        return $this->jsonResponse([array_merge(
            ['detectedLanguage' => ['language' => 'ru', 'score' => 1.0], 'translations' => [['text' => $text, 'to' => 'de']]],
            $extra
        )]);
    }

    /**
     * Query-Parameter des aufgezeichneten Requests.
     *
     * @return array<array-key, array<mixed>|string>
     */
    private function queryAt(int $index): array {
        parse_str($this->requestAt($index)->getUri()->getQuery(), $query);

        return $query;
    }

    public function test_is_available_requires_api_key(): void {
        $this->assertFalse((new AzureTranslatorProvider(''))->isAvailable());
        $this->assertTrue((new AzureTranslatorProvider('key'))->isAvailable());
    }

    public function test_translate_without_key_throws(): void {
        $this->expectException(TranslationException::class);
        (new AzureTranslatorProvider(''))->translate('Text', 'de');
    }

    public function test_translate_sends_key_and_parses_response(): void {
        $provider = $this->createProvider([$this->translationResponse()]);

        $result = $provider->translate('Привет мир', 'de');

        $request = $this->requestAt(0);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/translate', $request->getUri()->getPath());
        $this->assertSame('key', $request->getHeaderLine('Ocp-Apim-Subscription-Key'));
        $this->assertSame('', $request->getHeaderLine('Ocp-Apim-Subscription-Region'));

        $query = $this->queryAt(0);
        $this->assertSame('3.0', $query['api-version']);
        $this->assertSame('de', $query['to']);
        $this->assertArrayNotHasKey('from', $query);
        $this->assertArrayNotHasKey('textType', $query);

        $this->assertSame([['Text' => 'Привет мир']], $this->bodyAt(0));

        $this->assertSame('Hallo Welt', $result->text);
        $this->assertSame('ru', $result->detectedSourceLang);
        $this->assertSame('azure_translator', $result->provider);
        $this->assertSame(mb_strlen('Привет мир'), $result->charCount);
        $this->assertFalse($result->deterministicTerminology);
    }

    public function test_region_header_is_sent_when_configured(): void {
        $provider = $this->createProvider([$this->translationResponse()], region: 'westeurope');

        $provider->translate('Text', 'de');

        $this->assertSame('westeurope', $this->requestAt(0)->getHeaderLine('Ocp-Apim-Subscription-Region'));
    }

    public function test_source_lang_and_html_reach_query(): void {
        $provider = $this->createProvider([$this->translationResponse('<p>Hallo</p>')]);

        $provider->translate('<p>Hello</p>', 'de', 'en', new TranslateOptions(format: TextFormat::Html));

        $query = $this->queryAt(0);
        $this->assertSame('en', $query['from']);
        $this->assertSame('html', $query['textType']);
    }

    public function test_glossary_becomes_dynamic_dictionary_markup(): void {
        $provider = $this->createProvider([$this->translationResponse('Das Belegdatum')]);

        $result = $provider->translate('The invoice date', 'de', 'en', new TranslateOptions(
            glossary: [new GlossaryEntry('invoice date', 'Belegdatum')]
        ));

        $sent = $this->bodyAt(0)[0]['Text'];
        $this->assertSame(
            'The <mstrans:dictionary translation="Belegdatum">invoice date</mstrans:dictionary>',
            $sent
        );
        // Dictionary-Markup verlangt HTML-Modus.
        $this->assertSame('html', $this->queryAt(0)['textType']);
        $this->assertTrue($result->deterministicTerminology);
    }

    public function test_glossary_translation_is_attribute_escaped(): void {
        $provider = $this->createProvider([$this->translationResponse()]);

        $provider->translate('term', 'de', 'en', new TranslateOptions(
            glossary: [new GlossaryEntry('term', 'A "B" & C')]
        ));

        $this->assertStringContainsString('translation="A &quot;B&quot; &amp; C"', $this->bodyAt(0)[0]['Text']);
    }

    public function test_malformed_response_throws(): void {
        $provider = $this->createProvider([$this->jsonResponse([['translations' => []]])]);

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('translations[0].text fehlt');
        $provider->translate('Text', 'de');
    }

    public function test_auth_error_is_translated(): void {
        $provider = $this->createProvider([new Response(401)]);

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('Authentifizierung');
        $provider->translate('Text', 'de');
    }

    public function test_supported_target_languages_are_fetched_once(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translation' => ['DE' => ['name' => 'German'], 'en' => ['name' => 'English']]]),
        ]);

        $first = $provider->getSupportedTargetLanguages();
        $second = $provider->getSupportedTargetLanguages();

        $this->assertSame(['de', 'en'], $first);
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->history, 'Sprachliste darf nur einmal geholt werden');
        $this->assertSame('/languages', $this->requestAt(0)->getUri()->getPath());
    }
}
