<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CompositeProvider.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Providers;

use ERRORToolkit\Traits\ErrorLog;
use TranslationToolkit\Contracts\Interfaces\TranslationProviderInterface;
use TranslationToolkit\Entities\{TranslateOptions, TranslationResult};
use TranslationToolkit\Exceptions\TranslationException;

/**
 * Fallback-Kette über mehrere Provider: Aufrufe gehen an den ersten
 * verfügbaren Provider; scheitert er mit einer TranslationException, kommt
 * der nächste an die Reihe (z.B. LibreTranslate self-hosted -> DeepL).
 *
 * Der Name ist die stabile Verkettung aller Provider-Namen — er geht in den
 * Cache-Schlüssel ein und darf sich nicht danach richten, wer im Einzelfall
 * übersetzt hat. Das Ergebnis selbst trägt den tatsächlichen Provider.
 */
final class CompositeProvider implements TranslationProviderInterface {
    use ErrorLog;

    /** @var list<TranslationProviderInterface> */
    private readonly array $providers;

    public function __construct(TranslationProviderInterface ...$providers) {
        if ($providers === []) {
            throw new TranslationException('CompositeProvider benötigt mindestens einen Provider');
        }

        $this->providers = array_values($providers);
    }

    public function getName(): string {
        return implode('+', array_map(
            static fn (TranslationProviderInterface $p): string => $p->getName(),
            $this->providers
        ));
    }

    public function isAvailable(): bool {
        foreach ($this->providers as $provider) {
            if ($provider->isAvailable()) {
                return true;
            }
        }

        return false;
    }

    public function supportsGlossary(): bool {
        foreach ($this->providers as $provider) {
            if ($provider->isAvailable() && $provider->supportsGlossary()) {
                return true;
            }
        }

        return false;
    }

    public function preflight(): void {
        $this->attempt(static function (TranslationProviderInterface $provider): bool {
            $provider->preflight();

            return true;
        });
    }

    public function translate(
        string $text,
        string $targetLang,
        ?string $sourceLang = null,
        ?TranslateOptions $options = null,
    ): TranslationResult {
        return $this->attempt(
            static fn (TranslationProviderInterface $provider): TranslationResult => $provider->translate($text, $targetLang, $sourceLang, $options)
        );
    }

    public function translateBatch(
        array $texts,
        string $targetLang,
        ?string $sourceLang = null,
        ?TranslateOptions $options = null,
    ): array {
        return $this->attempt(
            static fn (TranslationProviderInterface $provider): array => $provider->translateBatch($texts, $targetLang, $sourceLang, $options)
        );
    }

    /**
     * Vereinigung der Zielsprachen aller verfügbaren Provider; Provider,
     * deren Liste nicht abrufbar ist, werden übersprungen.
     */
    public function getSupportedTargetLanguages(): array {
        $languages = [];
        $lastError = null;

        foreach ($this->providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }

            try {
                $languages = array_merge($languages, $provider->getSupportedTargetLanguages());
            } catch (TranslationException $e) {
                $lastError = $e;
            }
        }

        if ($languages === []) {
            throw $lastError ?? new TranslationException('Kein Übersetzungs-Provider verfügbar');
        }

        $languages = array_values(array_unique($languages));
        sort($languages);

        return $languages;
    }

    /**
     * Führt den Aufruf beim ersten verfügbaren Provider aus und fällt bei
     * TranslationExceptions auf die nächsten zurück.
     *
     * @template T
     * @param callable(TranslationProviderInterface): T $call
     * @return T
     */
    private function attempt(callable $call): mixed {
        $lastError = null;

        foreach ($this->providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }

            try {
                return $call($provider);
            } catch (TranslationException $e) {
                $lastError = $e;
                self::logWarning(sprintf(
                    'Übersetzungs-Provider "%s" fehlgeschlagen, versuche nächsten: %s',
                    $provider->getName(),
                    $e->getMessage()
                ));
            }
        }

        throw $lastError ?? new TranslationException('Kein Übersetzungs-Provider verfügbar');
    }
}
