<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TranslationUsageListenerInterface.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Contracts\Interfaces;

use TranslationToolkit\Entities\TranslationResult;

/**
 * Port für die Nutzungserfassung (Berechnungsgrundlage).
 *
 * Wird nach jeder Übersetzung aufgerufen — auch bei Cache-Hits
 * ($result->fromCache), damit Cache-Ersparnis ausweisbar bleibt.
 */
interface TranslationUsageListenerInterface {
    public function onTranslation(TranslationResult $result): void;
}
