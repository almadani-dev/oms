<?php

namespace App\Services\Muwakha\Import;

/**
 * One row of the normalized Muwakha import source, after preflight.
 *
 * A row is either READY — `$errors` empty and `$payload` holding the exact
 * array that WOULD be handed to MuwakhaFamilyService::create() — or in ERROR,
 * in which case `$payload` is null and every reason it failed is listed. The
 * preflight never stops at the first bad row, so `$errors` may hold several
 * messages, and `$payload` is built only for rows with none.
 *
 * The display fields (`martyrName` ... `bankTypeName`) are the values AS
 * SUPPLIED, echoed back for the report so an operator can find the row in the
 * spreadsheet. They are never re-derived from the payload: a row that failed
 * has no payload, and the report must still be able to name it.
 */
final class MuwakhaFamilyImportRow
{
    /**
     * @param  int  $index  zero-based position in the JSON array — the only
     *                      identifier that always exists, used when a row
     *                      carries no `source_row`
     * @param  int|null  $sourceRow  the spreadsheet row, exactly as supplied
     * @param  array<string, mixed>|null  $payload  MuwakhaFamilyService::create() input
     * @param  array<int, string>  $errors
     */
    private function __construct(
        public readonly int $index,
        public readonly ?int $sourceRow,
        public readonly string $martyrName,
        public readonly string $martyrNationalId,
        public readonly string $cardCode,
        public readonly string $currencyCode,
        public readonly string $bankTypeName,
        public readonly ?array $payload,
        public readonly array $errors,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<int, string>  $errors
     */
    public static function make(
        int $index,
        ?int $sourceRow,
        string $martyrName,
        string $martyrNationalId,
        string $cardCode,
        string $currencyCode,
        string $bankTypeName,
        ?array $payload,
        array $errors,
    ): self {
        // A payload alongside errors would be a row the report calls broken and
        // the importer could still write. The contract is enforced here rather
        // than trusted at every call site.
        return new self(
            index: $index,
            sourceRow: $sourceRow,
            martyrName: $martyrName,
            martyrNationalId: $martyrNationalId,
            cardCode: $cardCode,
            currencyCode: $currencyCode,
            bankTypeName: $bankTypeName,
            payload: $errors === [] ? $payload : null,
            errors: array_values($errors),
        );
    }

    /**
     * A copy carrying additional reasons this row cannot be imported. Used by
     * the cross-row and database-conflict passes, which run after every row has
     * been validated on its own. Adding an error DROPS the payload — that is
     * exactly the point.
     *
     * @param  array<int, string>  $errors
     */
    public function withErrors(array $errors): self
    {
        if ($errors === []) {
            return $this;
        }

        return self::make(
            index: $this->index,
            sourceRow: $this->sourceRow,
            martyrName: $this->martyrName,
            martyrNationalId: $this->martyrNationalId,
            cardCode: $this->cardCode,
            currencyCode: $this->currencyCode,
            bankTypeName: $this->bankTypeName,
            payload: $this->payload,
            errors: array_merge($this->errors, $errors),
        );
    }

    public function isReady(): bool
    {
        return $this->errors === [] && $this->payload !== null;
    }

    /**
     * How the report names this row: the supplied spreadsheet row when there is
     * one, otherwise its position in the file. Never invented — a row with no
     * `source_row` is labelled as a position so the two can never be confused.
     */
    public function label(): string
    {
        return $this->sourceRow !== null
            ? (string) $this->sourceRow
            : '#'.($this->index + 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function requirePayload(): array
    {
        if (! $this->isReady()) {
            throw new MuwakhaFamilyImportException(sprintf(
                'Source row %s has no importable payload.',
                $this->label(),
            ));
        }

        return $this->payload;
    }
}
