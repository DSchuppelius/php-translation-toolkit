# php-translation-toolkit

Schlankes Übersetzungs-Toolkit mit Provider-Abstraktion, Cache-Port und
DeepL-Provider auf Basis von `daniel-jorg-schuppelius/php-api-toolkit`
(Retry mit Backoff, Rate-Limit-Handling, Auth-Abstraktion, Log-Redaktion).

Entwurfsgrundlage: [ADR-0010](../ckonverter-architecture/adr/0010-translation-service-with-cache.md)
(Übersetzungsdienst mit Translation-Cache und Nutzungserfassung).

## Bausteine

| Baustein | Zweck |
| --- | --- |
| `TranslationService` | Orchestrierung: Cache prüfen → Provider aufrufen → Cache füllen → Nutzung melden |
| `TranslationProviderInterface` | Port für Provider (DeepL, LibreTranslate, ...) |
| `TranslationCacheInterface` | Port für den globalen Übersetzungs-Cache (DB-Implementierung beim Host) |
| `TranslationUsageListenerInterface` | Port für die Nutzungserfassung (Berechnungsgrundlage, auch Cache-Hits) |
| `Providers\DeepLProvider` | DeepL REST-API v2 via api-toolkit `ClientAbstract`; Free-Keys (`…:fx`) → api-free.deepl.com; für Tests Guzzle-Client injizierbar |
| `Caches\ArrayTranslationCache` | In-Memory-Cache für Tests/Kurzläufer |
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

`TranslationService::needsTranslation()` bildet die v1-Heuristik ab
(nicht-lateinische Zeichen und Mindestlänge), `cacheHash()` den
ADR-0010-Cache-Schlüssel `SHA-256(text|source|target|provider)`.

## Qualitätssicherung

```bash
composer test        # PHPUnit
composer lint        # PHPStan (Level 8)
composer format      # Pint (Check)
composer qa          # alles
```
