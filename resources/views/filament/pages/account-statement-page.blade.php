@php
    $money = fn ($value) => \App\Helpers\NumberHelper::bigComma($value) ?? '0.00';
    $dateTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i') : '-';
    $balanceClass = function (float $value) {
        if ($value > 0) {
            return 'acct-stmt-number acct-stmt-number-success';
        }

        if ($value < 0) {
            return 'acct-stmt-number acct-stmt-number-danger';
        }

        return 'acct-stmt-number';
    };
@endphp

<x-filament-panels::page>
    <style>
        .acct-stmt-page {
            --as-card-bg: #ffffff;
            --as-card-border: #e5e7eb;
            --as-card-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.04);
            --as-soft-bg: #f9fafb;
            --as-soft-border: #e5e7eb;
            --as-heading: #111827;
            --as-value: #111827;
            --as-muted: #6b7280;
            --as-th-bg: #f3f4f6;
            --as-th-text: #374151;
            --as-td-text: #374151;
            --as-table-border: #e5e7eb;
            --as-row-even: rgba(0, 0, 0, 0.02);
            --as-row-hover: rgba(0, 0, 0, 0.04);
            --as-number: #111827;
            --as-empty-border: #d1d5db;
            --as-success: #16a34a;
            --as-danger: #dc2626;
            --as-badge-bg: #f3f4f6;
            --as-badge-text: #374151;
            --as-badge-border: #e5e7eb;
            --as-warning-bg: #fef3c7;
            --as-warning-border: #fde68a;
            --as-warning-text: #b45309;

            direction: rtl;
            width: 100%;
        }

        .dark .acct-stmt-page {
            --as-card-bg: rgba(17, 24, 39, 0.76);
            --as-card-border: rgba(255, 255, 255, 0.10);
            --as-card-shadow: 0 8px 24px rgba(0, 0, 0, 0.14);
            --as-soft-bg: rgba(31, 41, 55, 0.55);
            --as-soft-border: rgba(255, 255, 255, 0.08);
            --as-heading: #ffffff;
            --as-value: #ffffff;
            --as-muted: #9ca3af;
            --as-th-bg: rgba(255, 255, 255, 0.06);
            --as-th-text: #e5e7eb;
            --as-td-text: #d1d5db;
            --as-table-border: rgba(255, 255, 255, 0.10);
            --as-row-even: rgba(255, 255, 255, 0.018);
            --as-row-hover: rgba(255, 255, 255, 0.035);
            --as-number: #f9fafb;
            --as-empty-border: rgba(255, 255, 255, 0.16);
            --as-success: #4ade80;
            --as-danger: #f87171;
            --as-badge-bg: rgba(255, 255, 255, 0.055);
            --as-badge-text: #d1d5db;
            --as-badge-border: rgba(255, 255, 255, 0.10);
            --as-warning-bg: rgba(245, 158, 11, 0.12);
            --as-warning-border: rgba(251, 191, 36, 0.24);
            --as-warning-text: #fde68a;
        }

        .acct-stmt-stack {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .acct-stmt-card {
            background: var(--as-card-bg);
            border: 1px solid var(--as-card-border);
            border-radius: 1rem;
            box-shadow: var(--as-card-shadow);
            padding: 1.25rem;
        }

        .acct-stmt-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--as-soft-border);
        }

        .acct-stmt-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .acct-stmt-summary-card {
            background: var(--as-soft-bg);
            border: 1px solid var(--as-soft-border);
            border-radius: 0.875rem;
            padding: 1rem;
        }

        .acct-stmt-muted {
            color: var(--as-muted);
            font-size: 0.82rem;
            font-weight: 650;
        }

        .acct-stmt-value {
            margin-top: 0.4rem;
            color: var(--as-value);
            font-size: 1.15rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }

        .acct-stmt-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: var(--as-badge-bg);
            color: var(--as-badge-text);
            border: 1px solid var(--as-badge-border);
            padding: 0.28rem 0.65rem;
            font-size: 0.78rem;
            font-weight: 750;
        }

        .acct-stmt-warning {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--as-warning-bg);
            border: 1px solid var(--as-warning-border);
            color: var(--as-warning-text);
            border-radius: 0.875rem;
            padding: 0.85rem 1rem;
            font-size: 0.85rem;
            font-weight: 700;
        }

        .acct-stmt-table-wrap {
            overflow-x: auto;
            border: 1px solid var(--as-table-border);
            border-radius: 0.875rem;
        }

        .acct-stmt-table {
            width: 100%;
            min-width: 960px;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .acct-stmt-table th {
            background: var(--as-th-bg);
            color: var(--as-th-text);
            font-weight: 750;
            padding: 0.85rem 1rem;
            text-align: right;
            white-space: nowrap;
        }

        .acct-stmt-table td {
            color: var(--as-td-text);
            padding: 0.85rem 1rem;
            border-top: 1px solid var(--as-table-border);
            vertical-align: top;
            line-height: 1.6;
        }

        .acct-stmt-table tbody tr:nth-child(even) td {
            background: var(--as-row-even);
        }

        .acct-stmt-table tbody tr:hover td {
            background: var(--as-row-hover);
        }

        .acct-stmt-number {
            direction: ltr;
            text-align: right;
            display: block;
            color: var(--as-number);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .acct-stmt-number-success {
            color: var(--as-success);
            font-weight: 800;
        }

        .acct-stmt-number-danger {
            color: var(--as-danger);
            font-weight: 800;
        }

        .acct-stmt-empty {
            border: 1px dashed var(--as-empty-border);
            border-radius: 0.875rem;
            padding: 2rem;
            color: var(--as-muted);
            text-align: center;
            font-weight: 700;
        }

        .acct-stmt-clamp {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            white-space: normal;
            word-break: break-word;
        }

        @media (max-width: 1100px) {
            .acct-stmt-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .acct-stmt-summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div dir="rtl" class="acct-stmt-page">
        <div class="acct-stmt-stack">
            <section class="acct-stmt-card">
                {{ $this->form }}

                <div class="acct-stmt-actions">
                    <x-filament::button
                        type="button"
                        icon="heroicon-o-magnifying-glass"
                        wire:click="showReport"
                    >
                        عرض
                    </x-filament::button>
                </div>
            </section>

            @if (! $hasSubmitted)
                <section class="acct-stmt-card">
                    <div class="acct-stmt-empty">يرجى اختيار الحساب والفترة ثم الضغط على عرض</div>
                </section>
            @else
                @if ($hasMixedCurrencies)
                    <div class="acct-stmt-warning">
                        تنبيه: تحتوي الفترة المحددة (أو الرصيد الافتتاحي) على حركات بعملات مختلفة عن عملة الحساب. قد لا تكون الإجماليات دقيقة.
                    </div>
                @endif

                <section class="acct-stmt-summary-grid">
                    <article class="acct-stmt-summary-card">
                        <div class="acct-stmt-muted">الحساب</div>
                        <div class="acct-stmt-value" style="font-size: 1rem;">{{ $selectedAccount->name }}</div>
                    </article>
                    <article class="acct-stmt-summary-card">
                        <div class="acct-stmt-muted">العملة</div>
                        <div class="acct-stmt-value">
                            <span class="acct-stmt-badge" dir="ltr">{{ $currencyCode ?: '—' }}</span>
                        </div>
                    </article>
                    <article class="acct-stmt-summary-card">
                        <div class="acct-stmt-muted">الرصيد الافتتاحي</div>
                        <div class="{{ $balanceClass($openingBalance) }}">{!! $money($openingBalance) !!}</div>
                    </article>
                    <article class="acct-stmt-summary-card">
                        <div class="acct-stmt-muted">الرصيد الختامي</div>
                        <div class="{{ $balanceClass($closingBalance) }}">{!! $money($closingBalance) !!}</div>
                    </article>
                    <article class="acct-stmt-summary-card">
                        <div class="acct-stmt-muted">إجمالي المدين</div>
                        <div class="acct-stmt-number">{!! $money($totalDebit) !!}</div>
                    </article>
                    <article class="acct-stmt-summary-card">
                        <div class="acct-stmt-muted">إجمالي الدائن</div>
                        <div class="acct-stmt-number">{!! $money($totalCredit) !!}</div>
                    </article>
                    <article class="acct-stmt-summary-card">
                        <div class="acct-stmt-muted">عدد الحركات</div>
                        <div class="acct-stmt-number">{{ $movementsCount }}</div>
                    </article>
                </section>

                <section class="acct-stmt-card">
                    @if ($movementsCount === 0)
                        <div class="acct-stmt-empty">لا توجد حركات ضمن الفترة المحددة</div>
                    @else
                        <div class="acct-stmt-table-wrap">
                            <table class="acct-stmt-table">
                                <thead>
                                    <tr>
                                        <th>التاريخ</th>
                                        <th>رقم الحركة</th>
                                        <th>نوع الحركة</th>
                                        <th>وصف العملية المالية</th>
                                        <th>دور سطر القيد</th>
                                        <th>وصف سطر القيد</th>
                                        <th>البيان / ملاحظات السطر</th>
                                        <th>مدين</th>
                                        <th>دائن</th>
                                        <th>الرصيد</th>
                                        <th>العملة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        <tr>
                                            <td>{{ $dateTime($row['date']) }}</td>
                                            <td dir="ltr">{{ $row['transaction_number'] ?: '-' }}</td>
                                            <td>{{ $row['type_name'] ?: '-' }}</td>
                                            <td style="min-width: 200px;">
                                                <div class="acct-stmt-clamp" title="{{ $row['description'] ?: '—' }}">{{ $row['description'] ?: '—' }}</div>
                                            </td>
                                            <td><span class="acct-stmt-badge">{{ $row['line_role_label'] }}</span></td>
                                            <td style="min-width: 200px;">
                                                <div class="acct-stmt-clamp" title="{{ $row['line_description'] }}">{{ $row['line_description'] }}</div>
                                            </td>
                                            <td>{{ $row['notes'] ?: '-' }}</td>
                                            <td>
                                                @if ($row['debit'] > 0)
                                                    <span class="acct-stmt-number">{!! $money($row['debit']) !!}</span>
                                                @else
                                                    <span class="acct-stmt-number" style="color: var(--as-muted);">-</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($row['credit'] > 0)
                                                    <span class="acct-stmt-number">{!! $money($row['credit']) !!}</span>
                                                @else
                                                    <span class="acct-stmt-number" style="color: var(--as-muted);">-</span>
                                                @endif
                                            </td>
                                            <td class="{{ $balanceClass($row['running_balance']) }}">{!! $money($row['running_balance']) !!}</td>
                                            <td>
                                                <span class="acct-stmt-badge" dir="ltr">{{ $row['currency_code'] ?: '-' }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
