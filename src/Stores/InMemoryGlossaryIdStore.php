<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : InMemoryGlossaryIdStore.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace TranslationToolkit\Stores;

use TranslationToolkit\Contracts\Interfaces\GlossaryIdStoreInterface;

/**
 * Merkt die Glossar-ID nur für die Lebensdauer des Prozesses. Ausreichend für
 * Tests und Kurzläufer; Langläufer sollten eine persistente Implementierung
 * hinterlegen, sonst legt jeder neue Prozess ein neues Remote-Glossar an.
 */
final class InMemoryGlossaryIdStore implements GlossaryIdStoreInterface {
    private ?string $glossaryId = null;
    private ?string $syncedFingerprint = null;

    public function get(): ?string {
        return $this->glossaryId;
    }

    public function set(string $glossaryId): void {
        $this->glossaryId = $glossaryId;
    }

    public function getSyncedFingerprint(): ?string {
        return $this->syncedFingerprint;
    }

    public function setSyncedFingerprint(string $fingerprint): void {
        $this->syncedFingerprint = $fingerprint;
    }
}
