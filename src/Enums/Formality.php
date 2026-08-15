<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Formality.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Enums;

/**
 * Förmlichkeit der Zielsprache (Anlehnung an DeepL-`formality`).
 * Provider ohne Förmlichkeits-Steuerung (Azure, LibreTranslate)
 * ignorieren den Wert.
 */
enum Formality: string {
    case Default = 'default';
    case More = 'more';
    case Less = 'less';
}
