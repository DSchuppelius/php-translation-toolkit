<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TranslationRegistry.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit;

/**
 * Statische Registry zur Injektion des TranslationService in Umgebungen ohne
 * Konstruktor-DI (analog ERRORToolkit\LoggerRegistry): der Host (z.B. ein
 * Worker-Bootstrap) setzt den Service, Bibliothekscode greift statisch zu.
 */
final class TranslationRegistry {
    private static ?TranslationService $service = null;

    public static function setService(?TranslationService $service): void {
        self::$service = $service;
    }

    public static function getService(): ?TranslationService {
        return self::$service;
    }

    public static function hasService(): bool {
        return self::$service !== null;
    }

    /**
     * Service gesetzt und Provider einsatzbereit?
     */
    public static function isAvailable(): bool {
        return self::$service !== null && self::$service->isAvailable();
    }

    public static function reset(): void {
        self::$service = null;
    }
}
