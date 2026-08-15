<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TranslationException.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Exceptions;

use RuntimeException;

/**
 * Fehler bei Konfiguration, Transport oder Provider-Antwort einer Übersetzung.
 */
class TranslationException extends RuntimeException {}
