<?php

namespace App\Services\Muwakha\Import;

use App\Models\BankType;
use App\Models\Currency;
use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyProject;
use App\Models\Project;
use App\Models\User;
use App\Services\Muwakha\MuwakhaFamilyService;
use App\Support\Muwakha\MuwakhaReference;
use App\Support\Muwakha\MuwakhaReferenceException;
use Illuminate\Support\Facades\Validator;
use JsonException;

/**
 * The read-only half of the one-time Muwakha families importer: it turns a
 * normalized JSON source file into a MuwakhaFamilyImportReport and writes
 * NOTHING. Every query below is a SELECT.
 *
 * WHY IT EXISTS AT ALL. MuwakhaFamilyService::create() is the official write
 * path and stays untouched, but a previous audit established that part of the
 * family's validation lives only in MuwakhaFamilyForm — the service re-checks
 * exactly the fields the Account identity structurally depends on (currency,
 * account number, bank type, holder name) and nothing else. A CLI import
 * therefore has no form to enforce `required`, `max:`, the date rules, the
 * integer floor or the martyr-id uniqueness. This class reproduces those rules
 * explicitly, field by field, against the REAL form (see rulesFor()), so an
 * imported family is validated exactly as a hand-entered one.
 *
 * It is a reproduction, never a relaxation: nothing here weakens a domain rule,
 * and everything here is additionally re-enforced downstream by the service,
 * MuwakhaFamilyProjectService and the database's own indexes.
 *
 * ALL ERRORS, NOT THE FIRST. Every row is validated, then the cross-row pass,
 * then the database-conflict pass. A row already in error is still carried
 * through both later passes so the report can state every reason at once — an
 * operator fixing a 72-row spreadsheet must not have to re-run 72 times.
 *
 * SOURCE DATA IS NOT "CORRECTED". The only transformation applied to a value is
 * a trim plus the approved technical null conversion: an empty string, or the
 * spreadsheet's «—» placeholder, becomes NULL. Nothing is deduplicated,
 * renumbered, merged or reformatted — a national id carrying a slash, a
 * DUMMY-MUW- id, a short id and a card code of «G 1111» all reach the payload
 * byte-for-byte as supplied. Placeholders in a field that is NOT nullable
 * surface as an ordinary `required` failure rather than being imported as the
 * literal «—».
 */
final class MuwakhaFamilyImportPreflight
{
    /**
     * The spreadsheet's "no value" placeholder. Converted to NULL, which the
     * nullable columns accept and the required ones reject through the same
     * `required` rule the form applies.
     */
    public const PLACEHOLDER = '—';

    /**
     * Validation messages are stated INLINE rather than left to the translator.
     *
     * This repository publishes no `lang/` files, so `validation.required` and
     * friends resolve to the raw translation KEY on a real installation — which
     * is exactly what a verification dry run showed. An import report an
     * operator cannot read is worse than no report, and the messages must not
     * depend on future localization work, so every rule this class uses carries
     * its own message here.
     */
    private const MESSAGES = [
        'required' => 'The :attribute field is required.',
        'string' => 'The :attribute field must be text.',
        'numeric' => 'The :attribute field must be a number.',
        'integer' => 'The :attribute field must be a whole number.',
        'min' => 'The :attribute field must be at least :min.',
        'max' => 'The :attribute field must not be longer/greater than :max.',
        'date' => 'The :attribute field must be a valid date.',
        'before_or_equal' => 'The :attribute field must be a date on or before :date.',
        'after_or_equal' => 'The :attribute field must be a date on or after :date.',
    ];

    /**
     * Every key the normalized source may carry. A key outside this list is
     * IGNORED for mapping and reported as a warning — never silently dropped —
     * because an unexpected key means either a display column (the spreadsheet
     * has an "age at martyrdom" column, which is computed and has no column on
     * the model) or a source-shape drift a human must confirm.
     */
    private const SOURCE_KEYS = [
        'source_row',
        'martyr_name',
        'martyr_national_id',
        'martyr_date_of_birth',
        'martyrdom_date',
        'children_count',
        'guardian_name',
        'guardian_national_id',
        'guardian_date_of_birth',
        'guardian_phone',
        'account_holder_name',
        'account_code',
        'bank_type_name',
        'currency_code',
        'iban',
        'card_code',
        'notes',
    ];

    public function run(
        string $filePath,
        string $projectCode,
        ?string $actorReference,
        int $expectedRowCount,
    ): MuwakhaFamilyImportReport {
        $fatalErrors = [];
        $warnings = [];

        // Reference resolution first: all three are whole-run preconditions, so
        // discovering them here means a broken installation is reported before
        // a single row is even parsed.
        $project = $this->resolveProject($projectCode, $fatalErrors);
        $actor = $this->resolveActor($actorReference, $fatalErrors);
        $this->assertAccountTypeResolvable($fatalErrors);

        $source = $this->readSource($filePath, $fatalErrors);

        if ($source === null) {
            return new MuwakhaFamilyImportReport(
                filePath: $filePath,
                projectCode: $projectCode,
                project: $project,
                actorReference: $actorReference,
                actor: $actor,
                expectedRowCount: $expectedRowCount,
                sourceRowCount: 0,
                rows: [],
                fatalErrors: $fatalErrors,
                warnings: $warnings,
            );
        }

        if (count($source) !== $expectedRowCount) {
            $fatalErrors[] = sprintf(
                'The source contains %d row(s); exactly %d were expected.',
                count($source),
                $expectedRowCount,
            );
        }

        $rows = [];

        foreach ($source as $index => $raw) {
            $rows[] = $this->prepareRow((int) $index, $raw, $project, $warnings);
        }

        $rows = $this->applyCrossRowChecks($rows);
        $rows = $this->applyDatabaseConflictChecks($rows, $project);

        return new MuwakhaFamilyImportReport(
            filePath: $filePath,
            projectCode: $projectCode,
            project: $project,
            actorReference: $actorReference,
            actor: $actor,
            expectedRowCount: $expectedRowCount,
            sourceRowCount: count($source),
            rows: $rows,
            fatalErrors: $fatalErrors,
            warnings: $warnings,
        );
    }

    /* ===================== source file ===================== */

    /**
     * @param  array<int, string>  $fatalErrors
     * @return array<int, mixed>|null
     */
    private function readSource(string $filePath, array &$fatalErrors): ?array
    {
        if (! is_file($filePath)) {
            $fatalErrors[] = sprintf('Source file not found: %s', $filePath);

            return null;
        }

        if (! is_readable($filePath)) {
            $fatalErrors[] = sprintf('Source file is not readable: %s', $filePath);

            return null;
        }

        $contents = file_get_contents($filePath);

        if ($contents === false) {
            $fatalErrors[] = sprintf('Source file could not be read: %s', $filePath);

            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $fatalErrors[] = sprintf('Source file is not valid JSON: %s', $e->getMessage());

            return null;
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            $fatalErrors[] = 'The source must be a JSON array of family objects.';

            return null;
        }

        return $decoded;
    }

    /* ===================== reference data ===================== */

    /**
     * The target project, resolved BY CODE and required to be a live, eligible
     * Muwakha project. No database id is ever accepted or assumed.
     *
     * Eligibility is not re-implemented: MuwakhaReference::eligibleProjectsQuery()
     * is the same query the form's project Select is built from and the same one
     * MuwakhaFamilyProjectService validates against. When the code matches
     * nothing eligible, a second lookup distinguishes "no such project" from
     * "soft-deleted" from "not a Muwakha project", because those are three
     * different operator actions.
     *
     * @param  array<int, string>  $fatalErrors
     */
    private function resolveProject(string $projectCode, array &$fatalErrors): ?Project
    {
        $projectCode = trim($projectCode);

        if ($projectCode === '') {
            $fatalErrors[] = 'No target project code was supplied.';

            return null;
        }

        try {
            $eligible = MuwakhaReference::eligibleProjectsQuery()->where('code', $projectCode)->get();
        } catch (MuwakhaReferenceException $e) {
            $fatalErrors[] = $e->getMessage();

            return null;
        }

        if ($eligible->count() === 1) {
            return $eligible->first();
        }

        if ($eligible->count() > 1) {
            $fatalErrors[] = sprintf(
                '%d eligible Muwakha projects carry the code «%s». Exactly one is required.',
                $eligible->count(),
                $projectCode,
            );

            return null;
        }

        $any = Project::withTrashed()->where('code', $projectCode)->first();

        if ($any === null) {
            $fatalErrors[] = sprintf('No project with the code «%s» exists.', $projectCode);
        } elseif ($any->trashed()) {
            $fatalErrors[] = sprintf(
                'Project «%s» (#%d) is soft-deleted and cannot receive an import.',
                $projectCode,
                $any->id,
            );
        } else {
            $fatalErrors[] = sprintf(
                'Project «%s» (#%d) is not a Muwakha project: it is not under the «%s» root.',
                $projectCode,
                $any->id,
                MuwakhaReference::PROJECT_SUPER_NAME,
            );
        }

        return null;
    }

    /**
     * The OMS user the import acts as, resolved from the users table by numeric
     * id or by email. Never invented, never a guest, never hard-coded.
     *
     * A soft-deleted or deactivated user is refused rather than used: those are
     * exactly the users User::canAccessPanel() denies, so attributing 72
     * families and their audit trail to one would record an actor who could not
     * have performed the action through the UI.
     *
     * Returning null WITHOUT a fatal error is the legitimate dry-run case where
     * no --actor was supplied; the report itself refuses to be importable then.
     *
     * @param  array<int, string>  $fatalErrors
     */
    private function resolveActor(?string $actorReference, array &$fatalErrors): ?User
    {
        $reference = trim((string) $actorReference);

        if ($reference === '') {
            return null;
        }

        $user = ctype_digit($reference)
            ? User::withTrashed()->whereKey((int) $reference)->first()
            : User::withTrashed()->where('email', $reference)->first();

        if ($user === null) {
            $fatalErrors[] = sprintf('No OMS user matches --actor=«%s» (expected a numeric id or an email).', $reference);

            return null;
        }

        if ($user->trashed()) {
            $fatalErrors[] = sprintf('The resolved actor «%s» (#%d) is soft-deleted.', (string) $user->email, $user->id);

            return null;
        }

        if (! $user->is_active) {
            $fatalErrors[] = sprintf('The resolved actor «%s» (#%d) is deactivated.', (string) $user->email, $user->id);

            return null;
        }

        return $user;
    }

    /**
     * The `أفراد` account type is a hard invariant of every Muwakha account.
     * The service resolves it itself before it opens its transaction; this only
     * proves it IS resolvable, so a missing or duplicated lookup is a reported
     * preflight failure instead of 72 identical exceptions at write time.
     *
     * @param  array<int, string>  $fatalErrors
     */
    private function assertAccountTypeResolvable(array &$fatalErrors): void
    {
        try {
            MuwakhaReference::accountTypeId();
        } catch (MuwakhaReferenceException $e) {
            $fatalErrors[] = $e->getMessage();
        }
    }

    /* ===================== one row ===================== */

    /**
     * @param  array<int, string>  $warnings
     */
    private function prepareRow(int $index, mixed $raw, ?Project $project, array &$warnings): MuwakhaFamilyImportRow
    {
        if (! is_array($raw) || array_is_list($raw)) {
            return MuwakhaFamilyImportRow::make(
                index: $index,
                sourceRow: null,
                martyrName: '',
                martyrNationalId: '',
                cardCode: '',
                currencyCode: '',
                bankTypeName: '',
                payload: null,
                errors: ['The source entry is not a JSON object.'],
            );
        }

        $values = [];

        foreach (self::SOURCE_KEYS as $key) {
            $values[$key] = $this->normalize($raw[$key] ?? null);
        }

        $sourceRow = is_numeric($values['source_row']) ? (int) $values['source_row'] : null;
        $label = $sourceRow !== null ? (string) $sourceRow : '#'.($index + 1);

        $unmapped = array_diff(array_keys($raw), self::SOURCE_KEYS);

        if ($unmapped !== []) {
            $warnings[] = sprintf(
                'Row %s: unmapped source key(s) ignored: %s.',
                $label,
                implode(', ', array_map(static fn (mixed $key): string => (string) $key, $unmapped)),
            );
        }

        $errors = $this->validateValues($values);

        // Reference lookups are attempted only when the field itself is present
        // and a string, so a blank currency produces one clear `required`
        // message rather than that plus a confusing "not found".
        $currency = null;
        $bankType = null;

        if (is_string($values['currency_code']) && $values['currency_code'] !== '') {
            $currency = $this->resolveCurrency($values['currency_code'], $errors);
        }

        if (is_string($values['bank_type_name']) && $values['bank_type_name'] !== '') {
            $bankType = $this->resolveBankType($values['bank_type_name'], $errors);
        }

        if ($project === null) {
            // Already reported as a fatal error; repeated per row only so no row
            // is ever shown as READY against an unresolved project.
            $errors[] = 'The target Muwakha project could not be resolved.';
        }

        $payload = ($errors === [] && $currency !== null && $bankType !== null && $project !== null)
            ? $this->buildPayload($values, $currency, $bankType, $project)
            : null;

        return MuwakhaFamilyImportRow::make(
            index: $index,
            sourceRow: $sourceRow,
            martyrName: $this->display($values['martyr_name']),
            martyrNationalId: $this->display($values['martyr_national_id']),
            cardCode: $this->display($values['card_code']),
            currencyCode: $this->display($values['currency_code']),
            bankTypeName: $this->display($values['bank_type_name']),
            payload: $payload,
            errors: $errors,
        );
    }

    /**
     * Trim, then the approved technical null conversion. NOTHING else: no case
     * folding, no digit normalization, no punctuation stripping — «/910717941»
     * and «DUMMY-MUW-G12» must survive byte-for-byte.
     *
     * Non-strings (the integer children_count, a stray boolean or array) are
     * passed through untouched for the validator to judge.
     */
    private function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return ($trimmed === '' || $trimmed === self::PLACEHOLDER) ? null : $trimmed;
    }

    private function display(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<int, string>
     */
    private function validateValues(array $values): array
    {
        $validator = Validator::make($values, $this->rulesFor($values), self::MESSAGES);

        if (! $validator->fails()) {
            return [];
        }

        $messages = [];

        foreach ($validator->errors()->all() as $message) {
            $messages[] = (string) $message;
        }

        return $messages;
    }

    /**
     * The MuwakhaFamilyForm rules, reproduced field by field.
     *
     * Each entry below mirrors one real form component, including the rules
     * Filament's own builders add: `->maxLength(n)` => `max:n`,
     * `->integer()` => `numeric` + `integer`, `->minValue(0)` => `min:0`,
     * a DatePicker => `date`, `->maxDate(today())` => `before_or_equal:today`,
     * `->afterOrEqual('martyr_date_of_birth')` => the same rule by field name.
     * `->tel()` deliberately adds NO rule, so guardian_phone carries only
     * required/max — a phone format rule here would be an invented rule.
     *
     * Two form rules are intentionally NOT expressed here, because they are
     * handled where they belong:
     *  - martyr_national_id's `->unique()`: checked against the database (and
     *    across soft-deleted rows) in applyDatabaseConflictChecks();
     *  - the bank type / currency Selects' option sets: resolved by name/code
     *    through MuwakhaReference so the report can name the missing lookup.
     *
     * And one form control is deliberately absent: `martyr_age_display` is a
     * Placeholder computed from the two dates. There is no age column on the
     * model, so the spreadsheet's age column is not imported at all.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array<int, string>>
     */
    private function rulesFor(array $values): array
    {
        $rules = [
            // Bookkeeping only — the spreadsheet row, used to name the row in
            // the report. Not a family field and never persisted.
            'source_row' => ['nullable', 'integer', 'min:1'],

            /* ---- بيانات الشهيد ---- */
            'martyr_name' => ['required', 'string', 'max:255'],
            'martyr_national_id' => ['required', 'string', 'max:50'],
            'martyr_date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'martyrdom_date' => ['required', 'date', 'before_or_equal:today'],
            'children_count' => ['required', 'numeric', 'integer', 'min:0'],

            /* ---- بيانات الوصي ---- */
            'guardian_name' => ['required', 'string', 'max:255'],
            'guardian_national_id' => ['nullable', 'string', 'max:50'],
            'guardian_date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'guardian_phone' => ['required', 'string', 'max:50'],

            /* ---- بيانات الحساب ---- */
            'account_holder_name' => ['required', 'string', 'max:255'],
            // Deliberately NOT unique: two families may legitimately share one
            // bank/wallet number, exactly as the form states.
            'account_code' => ['required', 'string', 'max:50'],
            'bank_type_name' => ['required', 'string'],
            'currency_code' => ['required', 'string'],
            'iban' => ['nullable', 'string', 'max:50'],

            /* ---- ربط بمشاريع المؤاخاة ---- */
            'card_code' => ['nullable', 'string', 'max:100'],

            /* ---- ملاحظات ---- */
            'notes' => ['nullable', 'string'],
        ];

        // The form's `->afterOrEqual('martyr_date_of_birth')`. Applied only when
        // both dates are actually present: with no date of birth there is no
        // comparison to make, which is the same thing the form means when the
        // (nullable) birth field is empty.
        if ($values['martyr_date_of_birth'] !== null && $values['martyrdom_date'] !== null) {
            $rules['martyrdom_date'][] = 'after_or_equal:martyr_date_of_birth';
        }

        return $rules;
    }

    /**
     * Resolved by `currencies.code` against the SAME live set the form's
     * currency Select is built from (MuwakhaReference::selectableCurrenciesQuery()),
     * so a code that would not be selectable in the UI is not importable either.
     * Soft-deleted currencies are excluded by the model's own scope.
     *
     * @param  array<int, string>  $errors
     */
    private function resolveCurrency(string $code, array &$errors): ?Currency
    {
        $matches = MuwakhaReference::selectableCurrenciesQuery()->where('code', $code)->get();

        if ($matches->isEmpty()) {
            $errors[] = sprintf('currency_code «%s» does not match any live OMS currency.', $code);

            return null;
        }

        if ($matches->count() > 1) {
            $errors[] = sprintf('%d live OMS currencies carry the code «%s».', $matches->count(), $code);

            return null;
        }

        return $matches->first();
    }

    /**
     * Resolved by the exact stored `bank_types.name`, against the same live set
     * the form's bank Select lists. Ambiguity is refused rather than guessed —
     * picking "the first" would attach a real beneficiary account to the wrong
     * payment rail.
     *
     * @param  array<int, string>  $errors
     */
    private function resolveBankType(string $name, array &$errors): ?BankType
    {
        $matches = BankType::query()->where('name', $name)->get();

        if ($matches->isEmpty()) {
            $errors[] = sprintf('bank_type_name «%s» does not match any live bank type.', $name);

            return null;
        }

        if ($matches->count() > 1) {
            $errors[] = sprintf('%d live bank types carry the name «%s».', $matches->count(), $name);

            return null;
        }

        return $matches->first();
    }

    /**
     * The EXACT array that would be passed to MuwakhaFamilyService::create() —
     * family columns, the four Account keys the service splits off, and the
     * create form's project-link rows under the service's own
     * PROJECT_LINKS_FIELD key.
     *
     * `account_type_id` is absent on purpose (the service resolves `أفراد`
     * itself and the form never submits it), `account_id` is absent (the
     * service assigns it), and the card code is passed through unnormalized —
     * blank-to-NULL belongs to MuwakhaFamilyProjectService.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function buildPayload(array $values, Currency $currency, BankType $bankType, Project $project): array
    {
        return [
            'martyr_name' => (string) $values['martyr_name'],
            'martyr_national_id' => (string) $values['martyr_national_id'],
            'martyr_date_of_birth' => $values['martyr_date_of_birth'],
            'martyrdom_date' => (string) $values['martyrdom_date'],
            'children_count' => (int) $values['children_count'],

            'guardian_name' => (string) $values['guardian_name'],
            'guardian_national_id' => $values['guardian_national_id'],
            'guardian_date_of_birth' => $values['guardian_date_of_birth'],
            'guardian_phone' => (string) $values['guardian_phone'],

            'account_holder_name' => (string) $values['account_holder_name'],
            'account_code' => (string) $values['account_code'],
            'bank_type_id' => (int) $bankType->id,
            'currency_id' => (int) $currency->id,
            'iban' => $values['iban'],

            'notes' => $values['notes'],

            MuwakhaFamilyService::PROJECT_LINKS_FIELD => [
                [
                    'project_id' => (int) $project->id,
                    'card_code' => $values['card_code'],
                ],
            ],
        ];
    }

    /* ===================== cross-row and database conflicts ===================== */

    /**
     * Duplicates WITHIN the source.
     *
     * Keyed on martyr_national_id and on card_code only — never on a name. Two
     * rows for one martyr with two different national ids are two separate
     * households and must both import; that is why no name-based check exists
     * anywhere in this class. Duplicate account_code values are likewise not a
     * conflict: the form documents them as legitimate.
     *
     * BOTH sides of a duplicate are flagged, because the operator has to decide
     * which row is wrong — the importer never picks one.
     *
     * @param  array<int, MuwakhaFamilyImportRow>  $rows
     * @return array<int, MuwakhaFamilyImportRow>
     */
    private function applyCrossRowChecks(array $rows): array
    {
        $rows = $this->flagDuplicates(
            $rows,
            static fn (MuwakhaFamilyImportRow $row): string => $row->martyrNationalId,
            static fn (string $value, string $others): string => sprintf(
                'Duplicate martyr_national_id «%s» within the source (also row(s) %s). '
                .'The uniqueness key is the national id, not the martyr name.',
                $value,
                $others,
            ),
        );

        return $this->flagDuplicates(
            $rows,
            static fn (MuwakhaFamilyImportRow $row): string => $row->cardCode,
            static fn (string $value, string $others): string => sprintf(
                'Duplicate card_code «%s» within the source for the target project (also row(s) %s).',
                $value,
                $others,
            ),
        );
    }

    /**
     * @param  array<int, MuwakhaFamilyImportRow>  $rows
     * @param  callable(MuwakhaFamilyImportRow): string  $keyFor
     * @param  callable(string, string): string  $message
     * @return array<int, MuwakhaFamilyImportRow>
     */
    private function flagDuplicates(array $rows, callable $keyFor, callable $message): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            $key = $keyFor($row);

            // An absent value is never a duplicate: several families in one
            // project may legitimately have no card code at all, and a missing
            // national id is already a `required` failure.
            if ($key === '') {
                continue;
            }

            $groups[$key][] = $index;
        }

        foreach ($groups as $key => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            foreach ($indexes as $index) {
                $others = array_values(array_filter(
                    $indexes,
                    static fn (int $other): bool => $other !== $index,
                ));

                $labels = implode(', ', array_map(
                    static fn (int $other): string => $rows[$other]->label(),
                    $others,
                ));

                $rows[$index] = $rows[$index]->withErrors([$message((string) $key, $labels)]);
            }
        }

        return $rows;
    }

    /**
     * Conflicts with what is ALREADY in the database — the check that makes an
     * accidental second run of this importer abort before any write instead of
     * creating 72 duplicate families.
     *
     * martyr_national_id is checked with withTrashed() on purpose: the column
     * carries a plain UNIQUE index, which MySQL enforces across soft-deleted
     * rows too (see the create_muwakha_families_table migration), so a
     * soft-deleted family still reserves its id. Card codes are checked within
     * the TARGET PROJECT only, which is the exact scope of the
     * (project_id, card_code) unique index.
     *
     * @param  array<int, MuwakhaFamilyImportRow>  $rows
     * @return array<int, MuwakhaFamilyImportRow>
     */
    private function applyDatabaseConflictChecks(array $rows, ?Project $project): array
    {
        $nationalIds = $this->distinctValues($rows, static fn (MuwakhaFamilyImportRow $row): string => $row->martyrNationalId);

        $existingFamilies = $nationalIds === []
            ? collect()
            : MuwakhaFamily::withTrashed()
                ->whereIn('martyr_national_id', $nationalIds)
                ->get(['id', 'martyr_national_id', 'deleted_at'])
                ->keyBy('martyr_national_id');

        $takenCardCodes = [];

        if ($project !== null) {
            $cardCodes = $this->distinctValues($rows, static fn (MuwakhaFamilyImportRow $row): string => $row->cardCode);

            if ($cardCodes !== []) {
                $takenCardCodes = MuwakhaFamilyProject::query()
                    ->where('project_id', $project->id)
                    ->whereIn('card_code', $cardCodes)
                    ->pluck('card_code')
                    ->all();
            }
        }

        foreach ($rows as $index => $row) {
            $errors = [];

            $existing = $row->martyrNationalId === '' ? null : $existingFamilies->get($row->martyrNationalId);

            if ($existing !== null) {
                $errors[] = sprintf(
                    'martyr_national_id «%s» already exists on muwakha_families #%d%s.',
                    $row->martyrNationalId,
                    $existing->id,
                    $existing->deleted_at !== null
                        ? ' (soft-deleted — the UNIQUE index still reserves this id)'
                        : '',
                );
            }

            if ($row->cardCode !== '' && in_array($row->cardCode, $takenCardCodes, true)) {
                $errors[] = sprintf(
                    'card_code «%s» is already used within the target project «%s».',
                    $row->cardCode,
                    $project?->code ?? '',
                );
            }

            if ($errors !== []) {
                $rows[$index] = $row->withErrors($errors);
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, MuwakhaFamilyImportRow>  $rows
     * @param  callable(MuwakhaFamilyImportRow): string  $valueFor
     * @return array<int, string>
     */
    private function distinctValues(array $rows, callable $valueFor): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = $valueFor($row);

            if ($value !== '') {
                $values[$value] = $value;
            }
        }

        return array_values($values);
    }
}
