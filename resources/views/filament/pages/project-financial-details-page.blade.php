@php
    $money = fn ($value, $currency = null) => $value === null || $value === ''
        ? '-'
        : number_format((float) $value, 2) . ($currency ? ' ' . $currency : '');

    $percent = fn ($value) => $value === null || $value === ''
        ? '-'
        : number_format((float) $value, 2) . '%';

    $date = fn ($value) => $value
        ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d')
        : '-';

    $dateTime = fn ($value) => $value
        ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i')
        : '-';

    $metricValue = function (array $row, string $currency) use ($money, $percent) {
        if (! array_key_exists($currency, $row['values'])) {
            return '-';
        }

        $value = $row['values'][$currency];

        if ($value === null || $value === '') {
            return $row['type'] === 'percentage' ? 'غير متاح' : '-';
        }

        return $row['type'] === 'percentage' ? $percent($value) : $money($value);
    };

    $reference = fn ($row) => filled($row['reference_type'] ?? null) || filled($row['reference_id'] ?? null)
        ? trim(($row['reference_type'] ?? '-') . ' #' . ($row['reference_id'] ?? '-'))
        : '-';

    $severityLabel = fn ($severity) => match ($severity) {
        'critical' => 'خطر',
        'warning' => 'تنبيه',
        'note' => 'ملاحظة',
        default => $severity ?: '-',
    };

    $severityClass = fn ($severity) => match ($severity) {
        'critical' => 'report-badge report-badge-danger',
        'warning' => 'report-badge report-badge-warning',
        'note' => 'report-badge report-badge-neutral',
        default => 'report-badge report-badge-neutral',
    };

    $severityRowClass = fn ($severity) => match ($severity) {
        'critical' => 'report-alert-row report-alert-row-danger',
        'warning' => 'report-alert-row report-alert-row-warning',
        'note' => 'report-alert-row report-alert-row-neutral',
        default => 'report-alert-row',
    };

    $badgeClass = fn ($color) => match ($color) {
        'danger' => 'report-badge report-badge-danger',
        'warning' => 'report-badge report-badge-warning',
        'success' => 'report-badge report-badge-success',
        'info', 'primary' => 'report-badge report-badge-info',
        default => 'report-badge report-badge-neutral',
    };

    $valueClass = function ($value, bool $percentage = false) {
        if ($value === null || $value === '') {
            return 'report-number report-muted-number';
        }

        $value = (float) $value;

        if ($value < 0) {
            return 'report-number report-number-danger';
        }

        if ($percentage) {
            return $value > 100 ? 'report-number report-number-warning' : 'report-number report-number-success';
        }

        return 'report-number';
    };

    $currencyClass = fn ($currency) => match ($currency) {
        'USD' => 'report-currency report-currency-success',
        'ILS' => 'report-currency report-currency-info',
        default => 'report-currency',
    };

    $projectCode = $projectInfo[0]['value'] ?? '-';
    $projectName = $projectInfo[1]['value'] ?? '-';
    $projectSuper = $projectInfo[2] ?? ['label' => 'المشروع الرئيسي', 'value' => '-'];
    $projectDonor = $projectInfo[3] ?? ['label' => 'المانح', 'value' => '-'];
    $projectStatus = $projectInfo[4] ?? ['label' => 'حالة المشروع', 'value' => '-'];
    $lastUpdated = $projectInfo[9] ?? ['label' => 'آخر تحديث', 'value' => '-'];
    $financialIndicator = $projectInfo[10] ?? ['label' => 'المؤشر المالي', 'value' => '-', 'badge' => 'gray'];
    $financialSafety = $projectInfo[11] ?? ['label' => 'السلامة المالية', 'value' => '-', 'badge' => 'gray'];
    $alertsTotal = $projectInfo[12]['value'] ?? number_format(array_sum($alertCounts));

    $summaryCards = [
        ['label' => 'كود المشروع', 'value' => $projectCode],
        ['label' => 'حالة المشروع', 'value' => $projectStatus['value'], 'badge' => 'info'],
        ['label' => 'المؤشر المالي', 'value' => $financialIndicator['value'], 'badge' => $financialIndicator['badge'] ?? 'gray'],
        ['label' => 'السلامة المالية', 'value' => $financialSafety['value'], 'badge' => $financialSafety['badge'] ?? 'gray'],
        ['label' => 'عدد التنبيهات', 'value' => $alertsTotal, 'class' => 'report-text-warning'],
        ['label' => 'عدد المخاطر الحرجة', 'value' => $alertCounts['critical'], 'class' => 'report-text-danger'],
        ['label' => 'المانح', 'value' => $projectDonor['value']],
        ['label' => 'آخر تحديث', 'value' => $lastUpdated['value'], 'ltr' => true],
    ];

    $navItems = [
        ['href' => '#project-info', 'label' => 'بيانات المشروع'],
        ['href' => '#financial-summary', 'label' => 'الملخص المالي'],
        ['href' => '#alerts', 'label' => 'التنبيهات'],
        ['href' => '#costs', 'label' => 'بنود التكلفة'],
        ['href' => '#receipts', 'label' => 'المقبوضات'],
        ['href' => '#budgets', 'label' => 'الصرف'],
        ['href' => '#payments', 'label' => 'المدفوعات'],
        ['href' => '#deductions', 'label' => 'الخصومات'],
    ];

    $projectMeta = array_slice($projectInfo, 0, 10);

    $detailSections = [
        [
            'id' => 'costs',
            'title' => 'بنود التكلفة',
            'subtitle' => 'قائمة بنود التكلفة المخططة للمشروع.',
            'rows' => $costs,
            'empty' => 'لا توجد بيانات',
            'minWidth' => '760px',
            'headers' => ['رقم البند', 'المبلغ', 'العملة', 'نوع الحساب', 'ملاحظات'],
            'cells' => fn ($cost) => [
                ['value' => $cost['id']],
                ['value' => $money($cost['amount']), 'number' => true, 'raw' => $cost['amount']],
                ['value' => $cost['currency_code'] ?: '-', 'currency' => $cost['currency_code'] ?: null],
                ['value' => $cost['account_type_name'] ?: ($cost['account_type_id'] ?: '-')],
                ['value' => $cost['notes'] ?: '-'],
            ],
        ],
        [
            'id' => 'receipts',
            'title' => 'المقبوضات',
            'subtitle' => 'حركات الاستلام والمبالغ المقبوضة حسب البنود.',
            'rows' => $receipts,
            'empty' => 'لا توجد بيانات',
            'minWidth' => '920px',
            'headers' => ['رقم المقبوض', 'رقم الحركة', 'التاريخ', 'رقم بند التكلفة', 'المبلغ', 'العملة', 'ملاحظات'],
            'cells' => fn ($receipt) => [
                ['value' => $receipt['id']],
                ['value' => $receipt['transaction_number'] ?: ($receipt['transaction_id'] ?: '-'), 'number' => true],
                ['value' => $date($receipt['date']), 'number' => true],
                ['value' => $receipt['project_cost_id']],
                ['value' => $money($receipt['amount']), 'number' => true, 'raw' => $receipt['amount']],
                ['value' => $receipt['currency_code'] ?: '-', 'currency' => $receipt['currency_code'] ?: null],
                ['value' => $receipt['notes'] ?: '-'],
            ],
        ],
        [
            'id' => 'budgets',
            'title' => 'الصرف / الميزانيات',
            'subtitle' => 'تفاصيل الصرف والخصومات والتحويلات لكل ميزانية.',
            'rows' => $budgets,
            'empty' => 'لا توجد بيانات',
            'minWidth' => '1380px',
            'headers' => ['رقم الصرف', 'رقم الحركة', 'رقم بند التكلفة', 'المبلغ الأصلي', 'عملة المصدر', 'نسبة الإداري', 'نسبة التحويل', 'نسبة الصرف', 'بعد الخصومات', 'سعر الصرف', 'المبلغ النهائي', 'عملة الصرف النهائي', 'ملاحظات'],
            'cells' => fn ($budget) => [
                ['value' => $budget['id']],
                ['value' => $budget['transaction_number'] ?: ($budget['transaction_id'] ?: '-'), 'number' => true],
                ['value' => $budget['project_cost_id']],
                ['value' => $money($budget['original_amount']), 'number' => true, 'raw' => $budget['original_amount']],
                ['value' => $budget['source_currency_code'] ?: '-', 'currency' => $budget['source_currency_code'] ?: null],
                ['value' => $percent($budget['administrative_percentage']), 'number' => true, 'percentage' => true, 'raw' => $budget['administrative_percentage']],
                ['value' => $percent($budget['transfer_percentage']), 'number' => true, 'percentage' => true, 'raw' => $budget['transfer_percentage']],
                ['value' => $percent($budget['exchange_percentage']), 'number' => true, 'percentage' => true, 'raw' => $budget['exchange_percentage']],
                ['value' => $money($budget['amount_after_deductions']), 'number' => true, 'raw' => $budget['amount_after_deductions']],
                ['value' => $budget['fx_rate'] === null ? '-' : number_format((float) $budget['fx_rate'], 6), 'number' => true],
                ['value' => $money($budget['final_amount']), 'number' => true, 'raw' => $budget['final_amount']],
                ['value' => $budget['disbursement_currency_code'] ?: '-', 'currency' => $budget['disbursement_currency_code'] ?: null],
                ['value' => $budget['notes'] ?: '-'],
            ],
        ],
        [
            'id' => 'payments',
            'title' => 'المدفوعات التنفيذية',
            'subtitle' => 'المدفوعات التنفيذية المرتبطة بالصرف وبنود التكلفة.',
            'rows' => $payments,
            'empty' => 'لا توجد بيانات',
            'minWidth' => '1040px',
            'headers' => ['رقم الدفعة', 'رقم الحركة', 'التاريخ', 'رقم الصرف', 'رقم بند التكلفة', 'المبلغ', 'العملة', 'ملاحظات'],
            'cells' => fn ($payment) => [
                ['value' => $payment['id']],
                ['value' => $payment['transaction_number'] ?: ($payment['transaction_id'] ?: '-'), 'number' => true],
                ['value' => $date($payment['date']), 'number' => true],
                ['value' => $payment['project_cost_budget_id']],
                ['value' => $payment['project_cost_id']],
                ['value' => $money($payment['amount']), 'number' => true, 'raw' => $payment['amount']],
                ['value' => $payment['currency_code'] ?: '-', 'currency' => $payment['currency_code'] ?: null],
                ['value' => $payment['notes'] ?: '-'],
            ],
        ],
        [
            'id' => 'deductions',
            'title' => 'الخصومات',
            'subtitle' => 'تفصيل الخصومات الإدارية والتحويل والصرف.',
            'rows' => $deductions,
            'empty' => 'لا توجد بيانات',
            'minWidth' => '820px',
            'headers' => ['رقم الصرف', 'الخصم الإداري', 'خصم التحويل', 'خصم الصرف', 'إجمالي الخصومات', 'العملة'],
            'cells' => fn ($deduction) => [
                ['value' => $deduction['budget_id']],
                ['value' => $money($deduction['admin']), 'number' => true, 'raw' => $deduction['admin']],
                ['value' => $money($deduction['transfer']), 'number' => true, 'raw' => $deduction['transfer']],
                ['value' => $money($deduction['exchange']), 'number' => true, 'raw' => $deduction['exchange']],
                ['value' => $money($deduction['total']), 'number' => true, 'raw' => $deduction['total']],
                ['value' => $deduction['currency_code'] ?: '-', 'currency' => $deduction['currency_code'] ?: null],
            ],
        ],
    ];
@endphp

<x-filament-panels::page>
    <style>
        .project-report-page {
            direction: rtl;
            width: 100%;
            max-width: 1500px;
            margin: 0 auto;
            padding: 1.5rem;
            color: #f9fafb;
            font-family: inherit;
        }

        .project-report-stack {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .project-report-card,
        .project-report-hero,
        .project-report-nav {
            background: rgba(17, 24, 39, 0.76);
            border: 1px solid rgba(255, 255, 255, 0.10);
            border-radius: 1rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.14);
        }

        .project-report-card {
            padding: 1.25rem;
            scroll-margin-top: 1.5rem;
        }

        .project-report-hero {
            position: relative;
            overflow: hidden;
            padding: 1.35rem;
            border-top: 3px solid rgba(245, 158, 11, 0.65);
        }

        .project-report-hero::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.08), transparent 45%);
        }

        .project-report-hero-inner {
            position: relative;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 280px;
            gap: 1.25rem;
            align-items: start;
        }

        .project-report-title {
            margin: 0.75rem 0 0.35rem;
            color: #ffffff;
            font-size: clamp(1.55rem, 2.2vw, 2rem);
            font-weight: 800;
            line-height: 1.35;
        }

        .project-report-name {
            margin: 0;
            color: #e5e7eb;
            font-size: 1.05rem;
            font-weight: 700;
            line-height: 1.7;
        }

        .project-report-subline {
            margin-top: 0.85rem;
            color: #9ca3af;
            font-size: 0.9rem;
            line-height: 1.8;
        }

        .project-report-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .project-report-action-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.4rem;
            padding: 0.65rem 1rem;
            border-radius: 0.75rem;
            background: #f59e0b;
            color: #111827;
            font-size: 0.875rem;
            font-weight: 800;
            text-decoration: none;
            transition: background 160ms ease, transform 160ms ease;
        }

        .project-report-action-button:hover {
            background: #fbbf24;
            transform: translateY(-1px);
        }

        .project-report-alert-box {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.65rem;
        }

        .project-report-card-soft {
            background: rgba(31, 41, 55, 0.55);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 0.875rem;
            padding: 1rem;
        }

        .project-report-muted {
            color: #9ca3af;
            font-size: 0.82rem;
            font-weight: 650;
        }

        .project-report-value {
            margin-top: 0.4rem;
            color: #ffffff;
            font-size: 0.95rem;
            font-weight: 750;
            line-height: 1.55;
            overflow-wrap: anywhere;
        }

        .project-report-value-lg {
            margin-top: 0.35rem;
            color: #ffffff;
            font-size: 1.5rem;
            font-weight: 850;
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }

        .project-report-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .project-report-summary-card {
            min-height: 6.35rem;
            background: rgba(31, 41, 55, 0.55);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 0.875rem;
            padding: 1rem;
        }

        .project-report-nav {
            padding: 0.75rem;
        }

        .project-report-nav-links {
            display: flex;
            flex-wrap: wrap;
            gap: 0.55rem;
        }

        .project-report-nav a {
            display: inline-flex;
            align-items: center;
            min-height: 2rem;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.035);
            border: 1px solid rgba(255, 255, 255, 0.09);
            color: #d1d5db;
            font-size: 0.8rem;
            font-weight: 700;
            text-decoration: none;
            transition: background 160ms ease, color 160ms ease, border-color 160ms ease;
        }

        .project-report-nav a:hover {
            color: #ffffff;
            background: rgba(245, 158, 11, 0.10);
            border-color: rgba(245, 158, 11, 0.30);
        }

        .project-report-section-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .project-report-section-title {
            margin: 0;
            color: #ffffff;
            font-size: 1.18rem;
            font-weight: 800;
            line-height: 1.4;
        }

        .project-report-section-subtitle {
            margin: 0.25rem 0 0;
            color: #9ca3af;
            font-size: 0.86rem;
            line-height: 1.65;
        }

        .project-report-count {
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.055);
            border: 1px solid rgba(255, 255, 255, 0.09);
            color: #d1d5db;
            padding: 0.4rem 0.75rem;
            font-size: 0.78rem;
            font-weight: 750;
        }

        .project-report-info-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        .project-report-alert-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.75rem;
            min-width: 410px;
        }

        .project-report-alert-stat {
            background: rgba(31, 41, 55, 0.55);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 0.875rem;
            padding: 0.85rem;
        }

        .project-report-alert-stat.danger {
            background: rgba(127, 29, 29, 0.22);
            border-color: rgba(248, 113, 113, 0.22);
        }

        .project-report-alert-stat.warning {
            background: rgba(120, 53, 15, 0.20);
            border-color: rgba(251, 191, 36, 0.22);
        }

        .project-report-table-wrap {
            overflow-x: auto;
            border: 1px solid rgba(255, 255, 255, 0.10);
            border-radius: 0.875rem;
        }

        .project-report-table {
            width: 100%;
            min-width: var(--table-min-width, 760px);
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .project-report-table th {
            background: rgba(255, 255, 255, 0.06);
            color: #e5e7eb;
            font-weight: 750;
            padding: 0.85rem 1rem;
            text-align: right;
            white-space: nowrap;
        }

        .project-report-table td {
            color: #d1d5db;
            padding: 0.85rem 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            vertical-align: top;
            line-height: 1.65;
        }

        .project-report-table tbody tr:nth-child(even) td,
        .project-report-table tbody tr:nth-child(even) th {
            background: rgba(255, 255, 255, 0.018);
        }

        .project-report-table tr:hover td,
        .project-report-table tr:hover th {
            background: rgba(255, 255, 255, 0.035);
        }

        .project-report-table tbody th {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: transparent;
            color: #f3f4f6;
        }

        .report-alert-row-danger td {
            background: rgba(127, 29, 29, 0.12);
            border-right: 3px solid rgba(248, 113, 113, 0.65);
        }

        .report-alert-row-warning td {
            background: rgba(120, 53, 15, 0.10);
            border-right: 3px solid rgba(251, 191, 36, 0.62);
        }

        .report-alert-row-neutral td {
            border-right: 3px solid rgba(148, 163, 184, 0.45);
        }

        .project-report-empty {
            border: 1px dashed rgba(255, 255, 255, 0.16);
            border-radius: 0.875rem;
            padding: 1.5rem;
            color: #9ca3af;
            text-align: center;
            font-weight: 700;
        }

        .report-badge,
        .report-currency {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.28rem 0.62rem;
            font-size: 0.74rem;
            font-weight: 800;
            line-height: 1;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .report-badge-danger {
            color: #fecaca;
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(248, 113, 113, 0.24);
        }

        .report-badge-warning {
            color: #fde68a;
            background: rgba(245, 158, 11, 0.12);
            border-color: rgba(251, 191, 36, 0.24);
        }

        .report-badge-success {
            color: #bbf7d0;
            background: rgba(34, 197, 94, 0.12);
            border-color: rgba(74, 222, 128, 0.24);
        }

        .report-badge-info,
        .report-currency-info {
            color: #bfdbfe;
            background: rgba(59, 130, 246, 0.12);
            border-color: rgba(96, 165, 250, 0.24);
        }

        .report-badge-neutral,
        .report-currency {
            color: #d1d5db;
            background: rgba(255, 255, 255, 0.055);
            border-color: rgba(255, 255, 255, 0.10);
        }

        .report-currency-success {
            color: #bbf7d0;
            background: rgba(34, 197, 94, 0.12);
            border-color: rgba(74, 222, 128, 0.24);
        }

        .report-number {
            direction: ltr;
            text-align: right;
            color: #f9fafb;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .report-muted-number {
            color: #9ca3af;
        }

        .report-number-danger,
        .report-text-danger {
            color: #fca5a5;
            font-weight: 800;
        }

        .report-number-warning,
        .report-text-warning {
            color: #fde68a;
            font-weight: 800;
        }

        .report-number-success {
            color: #bbf7d0;
            font-weight: 800;
        }

        .project-report-message-cell {
            max-width: 520px;
            white-space: normal;
            line-height: 1.7;
        }

        @@media (max-width: 1100px) {
            .project-report-hero-inner {
                grid-template-columns: 1fr;
            }

            .project-report-summary-grid,
            .project-report-info-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .project-report-alert-stats {
                min-width: 0;
                width: 100%;
            }
        }

        @@media (max-width: 720px) {
            .project-report-page {
                padding: 0.85rem;
            }

            .project-report-summary-grid,
            .project-report-info-grid,
            .project-report-alert-stats,
            .project-report-alert-box {
                grid-template-columns: 1fr;
            }

            .project-report-section-head {
                flex-direction: column;
            }
        }
    </style>

    <div dir="rtl" class="project-report-page">
        <div class="project-report-stack">
            <section class="project-report-hero">
                <div class="project-report-hero-inner">
                    <div>
                        <div>
                            <span class="report-badge report-badge-info" dir="ltr">{{ $projectCode }}</span>
                            <span class="report-badge report-badge-neutral">{{ $projectStatus['value'] }}</span>
                            <span class="{{ $badgeClass($financialIndicator['badge'] ?? 'gray') }}">{{ $financialIndicator['value'] }}</span>
                            <span class="{{ $badgeClass($financialSafety['badge'] ?? 'gray') }}">{{ $financialSafety['value'] }}</span>
                        </div>

                        <h1 class="project-report-title">التقرير المالي للمشروع</h1>
                        <p class="project-report-name">{{ $projectName }}</p>
                        <div class="project-report-subline">
                            {{ $projectSuper['label'] }}: {{ $projectSuper['value'] }}
                            <span aria-hidden="true"> · </span>
                            {{ $projectDonor['label'] }}: {{ $projectDonor['value'] }}
                        </div>
                    </div>

                    <div class="project-report-actions">
                        <a href="{{ \App\Filament\Pages\ProjectsGeneralFinancialPage::getUrl() }}" class="project-report-action-button">
                            العودة للتقرير العام
                        </a>
                        <div class="project-report-alert-box">
                            <div class="project-report-card-soft">
                                <div class="project-report-muted">عدد التنبيهات</div>
                                <div class="project-report-value-lg report-text-warning">{{ $alertsTotal }}</div>
                            </div>
                            <div class="project-report-card-soft">
                                <div class="project-report-muted">مخاطر حرجة</div>
                                <div class="project-report-value-lg report-text-danger">{{ $alertCounts['critical'] }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="project-report-summary-grid">
                @foreach ($summaryCards as $card)
                    <article class="project-report-summary-card">
                        <div class="project-report-muted">{{ $card['label'] }}</div>
                        <div class="project-report-value {{ $card['class'] ?? '' }}" @if ($card['ltr'] ?? false) dir="ltr" @endif>
                            @if (isset($card['badge']))
                                <span class="{{ $badgeClass($card['badge']) }}">{{ $card['value'] }}</span>
                            @else
                                {{ $card['value'] }}
                            @endif
                        </div>
                    </article>
                @endforeach
            </section>

            <nav class="project-report-nav">
                <div class="project-report-nav-links">
                    @foreach ($navItems as $item)
                        <a href="{{ $item['href'] }}">{{ $item['label'] }}</a>
                    @endforeach
                </div>
            </nav>

            <section id="project-info" class="project-report-card">
                <div class="project-report-section-head">
                    <div>
                        <h2 class="project-report-section-title">بيانات المشروع</h2>
                    </div>
                </div>
                <div class="project-report-info-grid">
                    @foreach ($projectMeta as $item)
                        <div class="project-report-card-soft">
                            <div class="project-report-muted">{{ $item['label'] }}</div>
                            <div class="project-report-value">{{ $item['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section id="financial-summary" class="project-report-card">
                <div class="project-report-section-head">
                    <div>
                        <h2 class="project-report-section-title">الملخص المالي حسب العملة</h2>
                        <p class="project-report-section-subtitle">ملخص القيم المالية حسب كل عملة دون خلط العملات.</p>
                    </div>
                    <span class="project-report-count">عدد العملات: {{ count($currencies) }}</span>
                </div>
                <div class="project-report-table-wrap">
                    <table class="project-report-table" style="--table-min-width: 760px;">
                        <thead>
                            <tr>
                                <th>البند</th>
                                @forelse ($currencies as $currency)
                                    <th dir="ltr">{{ $currency }}</th>
                                @empty
                                    <th>القيمة</th>
                                @endforelse
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($financialMatrix as $row)
                                <tr>
                                    <th>{{ $row['label'] }}</th>
                                    @forelse ($currencies as $currency)
                                        @php $rawValue = $row['values'][$currency] ?? null; @endphp
                                        <td class="{{ $valueClass($rawValue, $row['type'] === 'percentage') }}">
                                            {{ $metricValue($row, $currency) }}
                                        </td>
                                    @empty
                                        <td>-</td>
                                    @endforelse
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @foreach ($detailSections as $section)
                <section id="{{ $section['id'] }}" class="project-report-card">
                    <div class="project-report-section-head">
                        <div>
                            <h2 class="project-report-section-title">{{ $section['title'] }}</h2>
                            <p class="project-report-section-subtitle">{{ $section['subtitle'] }}</p>
                        </div>
                        <span class="project-report-count">عدد السجلات: {{ count($section['rows']) }}</span>
                    </div>

                    @if (count($section['rows']) === 0)
                        <div class="project-report-empty">{{ $section['empty'] }}</div>
                    @else
                        <div class="project-report-table-wrap">
                            <table class="project-report-table" style="--table-min-width: {{ $section['minWidth'] }};">
                                <thead>
                                    <tr>
                                        @foreach ($section['headers'] as $header)
                                            <th>{{ $header }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($section['rows'] as $row)
                                        <tr>
                                            @foreach ($section['cells']($row) as $cell)
                                                @if ($cell['currency'] ?? false)
                                                    <td><span class="{{ $currencyClass($cell['currency']) }}">{{ $cell['value'] }}</span></td>
                                                @elseif ($cell['number'] ?? false)
                                                    <td class="{{ $valueClass($cell['raw'] ?? null, $cell['percentage'] ?? false) }}">{{ $cell['value'] }}</td>
                                                @else
                                                    <td>{{ $cell['value'] }}</td>
                                                @endif
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endforeach

            <section id="alerts" class="project-report-card">
                <div class="project-report-section-head">
                    <div>
                        <h2 class="project-report-section-title">التنبيهات والمخاطر</h2>
                        <p class="project-report-section-subtitle">مؤشرات المخاطر والتنبيهات المالية المحسوبة لهذا المشروع.</p>
                    </div>
                    <div class="project-report-alert-stats">
                        <div class="project-report-alert-stat danger">
                            <div class="project-report-muted">مخاطر حرجة</div>
                            <div class="project-report-value-lg report-text-danger">{{ $alertCounts['critical'] }}</div>
                        </div>
                        <div class="project-report-alert-stat warning">
                            <div class="project-report-muted">تنبيهات</div>
                            <div class="project-report-value-lg report-text-warning">{{ $alertCounts['warning'] }}</div>
                        </div>
                        <div class="project-report-alert-stat">
                            <div class="project-report-muted">ملاحظات</div>
                            <div class="project-report-value-lg">{{ $alertCounts['note'] }}</div>
                        </div>
                    </div>
                </div>

                @if (count($alerts) === 0)
                    <div class="project-report-empty">لا توجد بيانات</div>
                @else
                    <div class="project-report-table-wrap">
                        <table class="project-report-table" style="--table-min-width: 1080px;">
                            <thead>
                                <tr>
                                    <th>المستوى</th>
                                    <th>المشكلة</th>
                                    <th>الرسالة</th>
                                    <th>العملة</th>
                                    <th>القيمة</th>
                                    <th>المرجع</th>
                                    <th>تاريخ الحساب</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($alerts as $alert)
                                    <tr class="{{ $severityRowClass($alert['severity'] ?? null) }}">
                                        <td>
                                            <span class="{{ $severityClass($alert['severity'] ?? null) }}">
                                                {{ $severityLabel($alert['severity'] ?? null) }}
                                            </span>
                                        </td>
                                        <td><strong>{{ $alert['title'] ?? '-' }}</strong></td>
                                        <td class="project-report-message-cell">{{ $alert['message'] ?? '-' }}</td>
                                        <td>
                                            @if (($alert['currency_code'] ?? null))
                                                <span class="{{ $currencyClass($alert['currency_code']) }}">{{ $alert['currency_code'] }}</span>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="{{ $valueClass($alert['amount'] ?? null) }}">{{ $money($alert['amount'] ?? null, $alert['currency_code'] ?? null) }}</td>
                                        <td class="report-number">{{ $reference($alert) }}</td>
                                        <td class="report-number">{{ $dateTime($alert['calculated_at'] ?? null) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
