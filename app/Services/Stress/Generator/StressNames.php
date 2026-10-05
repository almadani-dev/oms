<?php

namespace App\Services\Stress\Generator;

use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Support\Muwakha\MuwakhaReference;

/**
 * The existing lookup names the generator relies on. They are resolved to
 * ids from the stress database at build time (copied from the development
 * configuration) — never invented. A missing name aborts the build.
 */
final class StressNames
{
    public const STATUS_INCOMPLETE = 'غير مكتمل';

    public const STATUS_COMPLETE = 'مكتمل';

    public const TYPE_ORG = 'حساب الجمعية';

    public const TYPE_PARTNER_CHARITY = 'حسابات الشركاء (جمعية)';

    public const TYPE_PARTNER_COMPANY = 'حسابات الشركاء (شركات)';

    public const TYPE_PARTNER_PERSON = 'حسابات الشركاء (أفراد)';

    public const TYPE_EMPLOYEES = 'حسابات الموظفين';

    public const TYPE_OPENING = CreateAccount::OPENING_CLEARING_ACCOUNT_NAME;

    public const TYPE_INDIVIDUALS = MuwakhaReference::ACCOUNT_TYPE_NAME;

    public const REQUIRED_ACCOUNT_TYPES = [
        self::TYPE_ORG, self::TYPE_PARTNER_CHARITY, self::TYPE_PARTNER_COMPANY, self::TYPE_PARTNER_PERSON,
        self::TYPE_EMPLOYEES, self::TYPE_OPENING, self::TYPE_INDIVIDUALS,
    ];

    public const TX_RECEIPT = 'استلام مبلغ';

    public const TX_DISBURSEMENT = 'صرف مبلغ مشروع';

    public const TX_EXECUTION = 'مبلغ تنفيذ للمشروع';

    public const TX_EXCHANGE = 'تحويل بين الحسابات';

    public const TX_OPENING = CreateAccount::OPENING_TRANSACTION_TYPE_NAME;

    /** General-expense kind => existing transaction type name. */
    public const EXPENSE_TYPES = [
        'salary' => 'رواتب',
        'fuel' => 'محروقات',
        'maintenance' => 'صيانة مركبات',
        'electricity' => 'كهرباء',
        'aid_medical' => 'مساعدة علاج',
        'aid_marriage' => 'مساعدة زواج',
    ];

    public const REQUIRED_TRANSACTION_TYPES = [
        self::TX_RECEIPT, self::TX_DISBURSEMENT, self::TX_EXECUTION, self::TX_EXCHANGE, self::TX_OPENING,
        'رواتب', 'محروقات', 'صيانة مركبات', 'كهرباء', 'مساعدة علاج', 'مساعدة زواج',
    ];

    public const PARTNER_CHARITY = 'جمعية';

    public const PARTNER_PERSON = 'فرد';

    public const PARTNER_MINISTRY = 'وزارة';

    /** Wallet-style bank types hold ILS only; USDT holds USD only. */
    public const WALLETS = ['محفظة بال باي', 'محفظة جوال باي', 'فودافون كاش', 'محفظة مؤقت'];

    public const USDT = 'USDT';

    public const CASH = 'كاش';

    /** Day index of 2025-07-01: the former accountant records nothing from here on. */
    public const FORMER_ACCOUNTANT_UNTIL_DAY = 1277;
}
