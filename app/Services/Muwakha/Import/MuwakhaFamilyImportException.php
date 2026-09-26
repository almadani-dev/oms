<?php

namespace App\Services\Muwakha\Import;

use RuntimeException;

/**
 * Thrown when the one-time Muwakha family importer is asked to do something it
 * must refuse outright — as opposed to a row-level or file-level problem, which
 * is REPORTED (see MuwakhaFamilyImportReport) rather than thrown, because the
 * preflight's whole purpose is to surface every problem at once instead of
 * stopping at the first.
 *
 * In practice that means exactly one situation: an attempt to run the real
 * import from a report that is not importable. It is a programming error, not
 * an operator error — the command checks importability before it ever reaches
 * MuwakhaFamilyImporter — and it fails closed so a future caller cannot make
 * partial writes by skipping that check.
 *
 * The message is English because this exception never reaches the Filament UI;
 * it is a CLI/developer-facing failure.
 */
final class MuwakhaFamilyImportException extends RuntimeException
{
    public static function reportNotImportable(string $reason): self
    {
        return new self('Refusing to import: '.$reason.' Nothing was written.');
    }
}
