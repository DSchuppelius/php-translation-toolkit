<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TextFormat.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Enums;

/**
 * Format des Quelltexts: HTML aktiviert Markup-Schonung beim Provider
 * (DeepL `tag_handling`, Azure `textType=html`, LibreTranslate `format=html`).
 */
enum TextFormat: string {
    case Text = 'text';
    case Html = 'html';
}
