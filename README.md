# php-translation-toolkit

Schlankes Übersetzungs-Toolkit mit Provider-Abstraktion, Cache-Port und
Providern für DeepL, Azure Translator und LibreTranslate auf Basis von
`daniel-jorg-schuppelius/php-api-toolkit` (Retry mit Backoff,
Rate-Limit-Handling, Auth-Abstraktion, Log-Redaktion).

Entwurfsgrundlage: [ADR-0010](../ckonverter-architecture/adr/0010-translation-service-with-cache.md)
(Übersetzungsdienst mit Translation-Cache und Nutzungserfassung).

## Bausteine

| Baustein | Zweck |
| --- | --- |
| `TranslationService` | Orchestrierung: Cache prüfen → Provider aufrufen → Cache füllen → Nutzung melden |
| `TranslationProviderInterface` | Port für Provider (DeepL, Azure, LibreTranslate, ...) |
| `TranslationCacheInterface` | Port für den globalen Übersetzungs-Cache (DB-Implementierung beim Host) |
| `TranslationUsageListenerInterface` | Port für die Nutzungserfassung (Berechnungsgrundlage, auch Cache-Hits) |
| `GlossaryIdStoreInterface` | Port für die Persistenz einer Provider-Glossar-ID (DeepL v3) |
| `Entities\TranslateOptions` | Format, Förmlichkeit, Glossarbegriffe je Aufruf |
| `Entities\TranslationResult` | Ergebnis inkl. `charCount`, `fromCache`, `deterministicTerminology` |
| `Providers\DeepLProvider` | DeepL REST v2 + natives v3-Glossar; Free-Keys (`…:fx`) → api-free.deepl.com |
| `Providers\AzureTranslatorProvider` | Azure Translator v3.0 + Dynamic-Dictionary-Markup je Request |
| `Providers\LibreTranslateProvider` | Selbst gehostet, Glossar über Token-Maskierung |
| `Caches\ArrayTranslationCache` | In-Memory-Cache für Tests/Kurzläufer |
| `Stores\InMemoryGlossaryIdStore` | Glossar-ID für die Prozesslaufzeit (Tests/Kurzläufer) |
| `Providers\CompositeProvider` | Fallback-Kette: erster verfügbarer Provider übersetzt, bei Fehlern der nächste |
| `TranslationRegistry` | Statische Injektion für Umgebungen ohne Konstruktor-DI (analog `LoggerRegistry`) |

## Verwendung

```php
use TranslationToolkit\{TranslationRegistry, TranslationService};
use TranslationToolkit\Providers\DeepLProvider;

$service = new TranslationService(
    provider: new DeepLProvider($apiKey),
    cache: $myDbCache,            // TranslationCacheInterface, optional
    usageListener: $myUsageLog,   // TranslationUsageListenerInterface, optional
    defaultTargetLang: 'de',
);

// Direkt …
$result = $service->translate('Привет мир, как дела?');
$result->text;        // "Hallo Welt, wie geht es?"
$result->fromCache;   // false beim ersten, true beim zweiten Aufruf
$result->charCount;   // Berechnungsgrundlage (Zeichen des Quelltexts)

// … oder über die Registry (Host-Bootstrap setzt, Bibliothekscode liest)
TranslationRegistry::setService($service);
if (TranslationRegistry::isAvailable() && TranslationService::needsTranslation($text)) {
    $text = TranslationRegistry::getService()->translate($text)->text;
}
```

### Batch, Default-Optionen und Fallback

```php
// Viele Texte: Cache-Treffer einzeln, Misses gebündelt (DeepL 50/Azure 100 je Request)
$results = $service->translateMany($verwendungszwecke, 'de', 'ru');

// Host-Defaults (z.B. Mandanten-Glossar) greifen, wenn der Aufrufer nichts übergibt
$service = new TranslationService($provider, defaultOptions: new TranslateOptions(glossary: $mandantenGlossar));

// Fallback-Kette: self-hosted zuerst, Cloud als Reserve
$provider = new CompositeProvider(new LibreTranslateProvider($url), new DeepLProvider($key));
```

Der DeepL-Glossar-Sync läuft nur bei geänderten Einträgen (Fingerprint im
`GlossaryIdStoreInterface` — persistente Stores vermeiden so auch über
Prozessgrenzen unnötige Requests und Glossar-Neuanlagen).

`TranslationService::needsTranslation()` bildet die v1-Heuristik ab
(nicht-lateinische Zeichen und Mindestlänge), `cacheHash()` den
ADR-0010-Cache-Schlüssel `SHA-256(text|source|target|provider)`.

## Optionen: Format, Förmlichkeit, Glossar

```php
use TranslationToolkit\Entities\{GlossaryEntry, TranslateOptions};
use TranslationToolkit\Enums\{Formality, TextFormat};

$result = $service->translate('The invoice date is due', 'de', 'en', new TranslateOptions(
    format: TextFormat::Html,        // Markup schonen
    formality: Formality::More,      // förmlichere Zielsprache (DeepL)
    glossary: [new GlossaryEntry('invoice date', 'Belegdatum')],
));

$result->deterministicTerminology;   // true, wenn die Begriffe erzwungen wurden
```

`deterministicTerminology` beschreibt **diesen** Aufruf und ist bei Cache-Treffern
immer `false` — dort wird nicht übersetzt, und der Cache hält nur den Text. Da
Glossar *und* Quellsprache im Schlüssel stecken, stammt ein Treffer aber
garantiert aus einem Lauf mit identischen Vorgaben.

Wie ein Provider Terminologie erzwingt, unterscheidet sich — das Ergebnis führt
mit `deterministicTerminology` die **tatsächliche** Wirkung je Aufruf:

| Provider | Mechanik | Voraussetzung |
| --- | --- | --- |
| DeepL | natives multilinguales v3-Glossar, Dictionary je Sprachpaar wird idempotent voll ersetzt | **explizite Quellsprache** — ohne `sourceLang` bleibt das Glossar ungenutzt |
| Azure Translator | `<mstrans:dictionary translation="…">Begriff</mstrans:dictionary>` je Request | schaltet automatisch auf `textType=html` |
| LibreTranslate | Token-Maskierung vor/nach dem Aufruf | Begriff muss im Text vorkommen |

Nicht-Default-Optionen gehen in den Cache-Schlüssel ein; bei Defaults bleibt er
identisch zu Einträgen ohne Optionen (bestehende Caches behalten ihre Treffer).

Für DeepL sollte die Glossar-ID persistiert werden, sonst legt jeder Prozess ein
neues Remote-Glossar an:

```php
new DeepLProvider(
    apiKey: $apiKey,
    glossaryIdStore: $myPersistentStore,   // GlossaryIdStoreInterface
    glossaryName: 'meine-app-mandant-42',
);
```

## Qualitätssicherung

```bash
composer test        # PHPUnit
composer lint        # PHPStan (Level 8)
composer format      # Pint (Check)
composer qa          # alles
```
