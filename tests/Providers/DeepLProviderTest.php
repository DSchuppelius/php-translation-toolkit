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

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordsHttp;
use TranslationToolkit\Entities\{GlossaryEntry, TranslateOptions};
use TranslationToolkit\Enums\{Formality, TextFormat};
use TranslationToolkit\Exceptions\TranslationException;
use TranslationToolkit\Providers\DeepLProvider;
use TranslationToolkit\Stores\InMemoryGlossaryIdStore;

final class DeepLProviderTest extends TestCase {
    use RecordsHttp;

    /**
     * @param list<Response> $responses
     */
    private function createProvider(
        array $responses,
        string $apiKey = 'key',
        ?InMemoryGlossaryIdStore $store = null,
    ): DeepLProvider {
        return new DeepLProvider($apiKey, null, null, $this->mockClient($responses), $store);
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

        $body = $this->bodyAt(0);
        $this->assertSame(['Привет мир'], $body['text']);
        $this->assertSame('DE', $body['target_lang']);
        $this->assertArrayNotHasKey('source_lang', $body);
        $this->assertArrayNotHasKey('tag_handling', $body);
        $this->assertArrayNotHasKey('formality', $body);
        $this->assertArrayNotHasKey('glossary_id', $body);

        $this->assertSame('Hallo Welt', $result->text);
        $this->assertSame('ru', $result->detectedSourceLang);
        $this->assertSame('de', $result->targetLang);
        $this->assertSame('deepl', $result->provider);
        $this->assertSame(mb_strlen('Привет мир'), $result->charCount);
        $this->assertFalse($result->fromCache);
        $this->assertFalse($result->deterministicTerminology);
    }

    public function test_translate_passes_source_lang(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translations' => [['text' => 'Hello']]]),
        ]);

        $provider->translate('Hallo', 'en', 'de');

        $this->assertSame('DE', $this->bodyAt(0)['source_lang']);
    }

    public function test_html_and_formality_options_reach_payload(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translations' => [['text' => '<p>Hello</p>']]]),
        ]);

        $provider->translate('<p>Hallo</p>', 'en', 'de', new TranslateOptions(
            format: TextFormat::Html,
            formality: Formality::More,
        ));

        $body = $this->bodyAt(0);
        $this->assertSame('html', $body['tag_handling']);
        $this->assertSame('prefer_more', $body['formality']);
    }

    public function test_glossary_is_created_and_id_reused(): void {
        $store = new InMemoryGlossaryIdStore;
        $provider = $this->createProvider([
            $this->jsonResponse(['glossary_id' => 'gl-1']),
            $this->jsonResponse(['translations' => [['text' => 'Belegdatum']]]),
        ], store: $store);

        $options = new TranslateOptions(glossary: [new GlossaryEntry('invoice date', 'Belegdatum')]);
        $result = $provider->translate('invoice date', 'de', 'en', $options);

        // 1. Request: Glossar-Anlage
        $create = $this->requestAt(0);
        $this->assertSame('POST', $create->getMethod());
        $this->assertSame('/v3/glossaries', $create->getUri()->getPath());
        $dictionary = $this->bodyAt(0)['dictionaries'][0];
        $this->assertSame('EN', $dictionary['source_lang']);
        $this->assertSame('DE', $dictionary['target_lang']);
        $this->assertSame("invoice date\tBelegdatum", $dictionary['entries']);
        $this->assertSame('tsv', $dictionary['entries_format']);

        // 2. Request: Übersetzung mit Glossar-ID
        $this->assertSame('/v2/translate', $this->requestAt(1)->getUri()->getPath());
        $this->assertSame('gl-1', $this->bodyAt(1)['glossary_id']);

        $this->assertTrue($result->deterministicTerminology);
        $this->assertSame('gl-1', $store->get());
    }

    public function test_known_glossary_is_updated_in_place(): void {
        $store = new InMemoryGlossaryIdStore;
        $store->set('gl-existing');
        $provider = $this->createProvider([
            $this->jsonResponse([]),
            $this->jsonResponse(['translations' => [['text' => 'Belegdatum']]]),
        ], store: $store);

        $provider->translate('invoice date', 'de', 'en', new TranslateOptions(
            glossary: [new GlossaryEntry('invoice date', 'Belegdatum')]
        ));

        $update = $this->requestAt(0);
        $this->assertSame('PUT', $update->getMethod());
        $this->assertSame('/v3/glossaries/gl-existing/dictionaries', $update->getUri()->getPath());
        $this->assertSame('gl-existing', $store->get());
    }

    public function test_vanished_glossary_is_recreated(): void {
        $store = new InMemoryGlossaryIdStore;
        $store->set('gl-gone');
        $provider = $this->createProvider([
            new Response(404),
            $this->jsonResponse(['glossary_id' => 'gl-new']),
            $this->jsonResponse(['translations' => [['text' => 'Belegdatum']]]),
        ], store: $store);

        $result = $provider->translate('invoice date', 'de', 'en', new TranslateOptions(
            glossary: [new GlossaryEntry('invoice date', 'Belegdatum')]
        ));

        $this->assertSame('PUT', $this->requestAt(0)->getMethod());
        $this->assertSame('/v3/glossaries', $this->requestAt(1)->getUri()->getPath());
        $this->assertSame('gl-new', $this->bodyAt(2)['glossary_id']);
        $this->assertSame('gl-new', $store->get());
        $this->assertTrue($result->deterministicTerminology);
    }

    public function test_glossary_without_source_lang_is_skipped(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translations' => [['text' => 'Belegdatum']]]),
        ]);

        $result = $provider->translate('invoice date', 'de', null, new TranslateOptions(
            glossary: [new GlossaryEntry('invoice date', 'Belegdatum')]
        ));

        // DeepL-Glossare brauchen ein Sprachpaar: kein Sync, keine Erzwingung.
        $this->assertCount(1, $this->history);
        $this->assertSame('/v2/translate', $this->requestAt(0)->getUri()->getPath());
        $this->assertArrayNotHasKey('glossary_id', $this->bodyAt(0));
        $this->assertFalse($result->deterministicTerminology);
    }

    public function test_glossary_entries_without_translation_are_ignored(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translations' => [['text' => 'Text']]]),
        ]);

        $result = $provider->translate('Text', 'de', 'en', new TranslateOptions(
            glossary: [new GlossaryEntry('term', '  ')]
        ));

        $this->assertCount(1, $this->history);
        $this->assertFalse($result->deterministicTerminology);
    }

    public function test_translate_with_malformed_response_throws(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translations' => []]),
        ]);

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('Anzahl der Übersetzungen passt nicht zur Anfrage');
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

    public function test_preflight_calls_usage(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['character_count' => 0, 'character_limit' => 500000]),
        ]);

        $provider->preflight();

        $this->assertSame('/v2/usage', $this->requestAt(0)->getUri()->getPath());
    }
    public function test_translate_batch_bundles_texts_in_one_request(): void {
        $provider = $this->createProvider([
            $this->jsonResponse(['translations' => [['text' => 'Hallo'], ['text' => 'Welt']]]),
        ]);

        $results = $provider->translateBatch(['Hello', 'World'], 'de');

        $this->assertCount(1, $this->history, 'Beide Texte müssen in einem Request laufen');
        $this->assertSame(['Hello', 'World'], $this->bodyAt(0)['text']);
        $this->assertSame('Hallo', $results[0]->text);
        $this->assertSame('Welt', $results[1]->text);
        $this->assertSame('Hello', $results[0]->sourceText);
    }

    public function test_translate_batch_chunks_above_api_limit(): void {
        $texts = array_map(static fn (int $i): string => "Text {$i}", range(1, 51));
        $firstChunk = array_map(static fn (int $i): array => ['text' => "Übersetzt {$i}"], range(1, 50));
        $provider = $this->createProvider([
            $this->jsonResponse(['translations' => $firstChunk]),
            $this->jsonResponse(['translations' => [['text' => 'Übersetzt 51']]]),
        ]);

        $results = $provider->translateBatch($texts, 'de');

        $this->assertCount(2, $this->history, '51 Texte müssen auf zwei Requests verteilt werden');
        $this->assertCount(50, $this->bodyAt(0)['text']);
        $this->assertCount(1, $this->bodyAt(1)['text']);
        $this->assertCount(51, $results);
        $this->assertSame('Übersetzt 51', $results[50]->text);
    }

    public function test_unchanged_glossary_is_synced_only_once(): void {
        $store = new InMemoryGlossaryIdStore;
        $provider = $this->createProvider([
            $this->jsonResponse(['glossary_id' => 'gl-1']),
            $this->jsonResponse(['translations' => [['text' => 'Belegdatum']]]),
            $this->jsonResponse(['translations' => [['text' => 'Belegdatum']]]),
        ], store: $store);

        $options = new TranslateOptions(glossary: [new GlossaryEntry('invoice date', 'Belegdatum')]);
        $provider->translate('invoice date', 'de', 'en', $options);
        $provider->translate('invoice date is due', 'de', 'en', $options);

        // Anlage + 2x Übersetzung — KEIN zweiter Glossar-Sync
        $this->assertCount(3, $this->history);
        $this->assertSame('/v3/glossaries', $this->requestAt(0)->getUri()->getPath());
        $this->assertSame('/v2/translate', $this->requestAt(1)->getUri()->getPath());
        $this->assertSame('/v2/translate', $this->requestAt(2)->getUri()->getPath());
    }

    public function test_changed_glossary_triggers_resync(): void {
        $store = new InMemoryGlossaryIdStore;
        $provider = $this->createProvider([
            $this->jsonResponse(['glossary_id' => 'gl-1']),
            $this->jsonResponse(['translations' => [['text' => 'Belegdatum']]]),
            $this->jsonResponse([]),
            $this->jsonResponse(['translations' => [['text' => 'Rechnungsdatum']]]),
        ], store: $store);

        $provider->translate('invoice date', 'de', 'en', new TranslateOptions(
            glossary: [new GlossaryEntry('invoice date', 'Belegdatum')]
        ));
        $provider->translate('invoice date', 'de', 'en', new TranslateOptions(
            glossary: [new GlossaryEntry('invoice date', 'Rechnungsdatum')]
        ));

        // Geänderte Einträge -> PUT auf das bestehende Glossar vor der zweiten Übersetzung
        $this->assertCount(4, $this->history);
        $this->assertSame('PUT', $this->requestAt(2)->getMethod());
        $this->assertSame('/v3/glossaries/gl-1/dictionaries', $this->requestAt(2)->getUri()->getPath());
    }

    public function test_unsupported_target_language_fails_without_request(): void {
        $provider = $this->createProvider([]);

        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('Zielsprache "xx"');
        $provider->translate('Text', 'xx');
    }
}
