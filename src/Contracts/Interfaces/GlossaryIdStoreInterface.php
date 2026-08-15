<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GlossaryIdStoreInterface.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Contracts\Interfaces;

/**
 * Port für die Persistenz einer Provider-Glossar-ID (z.B. DeepL-v3-Glossar).
 *
 * Der Host entscheidet den Geltungsbereich (pro Verbindung, pro Mandant, ...)
 * und legt die ID z.B. an seiner Verbindungs-Konfiguration ab. Ohne Store
 * merkt sich der Provider die ID nur pro Instanz — jeder neue Prozess legt
 * dann ein neues Remote-Glossar an.
 */
interface GlossaryIdStoreInterface {
    public function get(): ?string;

    public function set(string $glossaryId): void;
}
