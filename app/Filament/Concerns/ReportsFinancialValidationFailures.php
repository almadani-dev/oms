<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Makes a financial guard rejection VISIBLE to the operator.
 *
 * The five financial workflows re-validate every amount, account and journal
 * line server-side through FinancialAmountGuard / FinancialAccountGuard /
 * FinancialTransactionBalanceGuard, each of which throws a
 * ValidationException. Those exceptions are keyed by the DOMAIN field name
 * (`original_amount`, `admin_account_id`, and — for every line-level check —
 * the synthetic key `lines`), whereas a Filament form resolves its errors by
 * STATE PATH (`data.original_amount`). Livewire stores the bag verbatim
 * (SupportValidation::exception() -> setErrorBag($e->validator->errors())),
 * and Filament's CreateRecord/EditRecord re-throw a Throwable without
 * sending any notification — so none of these messages was reaching the
 * screen. The page simply did nothing, which is indistinguishable from a
 * dead form.
 *
 * This is the deliberately small fix: surface the messages the guards
 * already wrote, in Arabic, as a danger notification. It is NOT the
 * repo-wide guard-key refactor (prefixing every throw with `data.` so the
 * message also lands under its own field) — that is a separate task
 * touching all six workflows.
 *
 * SEMANTICS ARE UNCHANGED AND MUST STAY UNCHANGED: the exception is always
 * re-thrown. Filament still rolls its wrapping database transaction back,
 * the record is still not created or updated, and a failed financial
 * operation is never reported to the user as a success. This trait only
 * adds a message; it never decides an outcome.
 */
trait ReportsFinancialValidationFailures
{
    /**
     * Run a financial create/update body, announcing any guard rejection
     * before letting it propagate untouched.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     *
     * @throws ValidationException
     */
    protected function withVisibleFinancialValidation(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('تعذر حفظ العملية المالية')
                ->body(self::flattenValidationMessages($exception))
                ->danger()
                ->persistent()
                ->send();

            throw $exception;
        }
    }

    /**
     * One readable Arabic string from the exception's message bag.
     *
     * Bounded on purpose: a notification body is not a log. Duplicates are
     * collapsed (several account roles can fail with the same wording) and
     * the result is capped, so a pathological payload can never render an
     * unbounded toast.
     */
    protected static function flattenValidationMessages(ValidationException $exception): string
    {
        $messages = array_values(array_unique(array_filter(
            Arr::flatten($exception->errors()),
            static fn (mixed $message): bool => is_string($message) && $message !== '',
        )));

        if ($messages === []) {
            return 'تم رفض العملية أثناء التحقق المالي.';
        }

        $body = implode(' ', array_slice($messages, 0, 5));

        return mb_strlen($body) > 500 ? mb_substr($body, 0, 499) . '…' : $body;
    }
}
