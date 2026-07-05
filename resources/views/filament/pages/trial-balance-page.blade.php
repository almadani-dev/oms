@php
    $money = fn ($value) => \App\Helpers\NumberHelper::bigComma($value) ?? '0.00';
@endphp

<x-filament-panels::page>
    <style>
        .tb-page {
            --tb-card-bg: #ffffff;
            --tb-card-border: #e5e7eb;
            --tb-card-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.04);
            --tb-soft-bg: #f9fafb;
            --tb-soft-border: #e5e7eb;
            --tb-heading: #111827;
            --tb-value: #111827;
            --tb-muted: #6b7280;
            --tb-th-bg: #f3f4f6;
            --tb-th-text: #374151;
            --tb-td-text: #374151;
            --tb-table-border: #e5e7eb;
            --tb-row-even: rgba(0, 0, 0, 0.02);
            --tb-row-hover: rgba(0, 0, 0, 0.04);
            --tb-number: #111827;
            --tb-empty-border: #d1d5db;
            --tb-success: #16a34a;
            --tb-danger: #dc2626;
            --tb-badge-success-bg: #dcfce7;
            --tb-badge-success-border: #bbf7d0;
            --tb-badge-danger-bg: #fee2e2;
            --tb-badge-danger-border: #fecaca;
            --tb-badge-bg: #f3f4f6;
            --tb-badge-text: #374151;
            --tb-badge-border: #e5e7eb;

            direction: rtl;
            width: 100%;
        }

        .dark .tb-page {
            --tb-card-bg: rgba(17, 24, 39, 0.76);
            --tb-card-border: rgba(255, 255, 255, 0.10);
            --tb-card-shadow: 0 8px 24px rgba(0, 0, 0, 0.14);
            --tb-soft-bg: rgba(31, 41, 55, 0.55);
            --tb-soft-border: rgba(255, 255, 255, 0.08);
            --tb-heading: #ffffff;
            --tb-value: #ffffff;
            --tb-muted: #9ca3af;
            --tb-th-bg: rgba(255, 255, 255, 0.06);
            --tb-th-text: #e5e7eb;
            --tb-td-text: #d1d5db;
            --tb-table-border: rgba(255, 255, 255, 0.10);
            --tb-row-even: rgba(255, 255, 255, 0.018);
            --tb-row-hover: rgba(255, 255, 255, 0.035);
            --tb-number: #f9fafb;
            --tb-empty-border: rgba(255, 255, 255, 0.16);
            --tb-success: #4ade80;
            --tb-danger: #f87171;
            --tb-badge-success-bg: rgba(74, 222, 128, 0.14);
            --tb-badge-success-border: rgba(74, 222, 128, 0.28);
            --tb-badge-danger-bg: rgba(248, 113, 113, 0.14);
            --tb-badge-danger-border: rgba(248, 113, 113, 0.28);
            --tb-badge-bg: rgba(255, 255, 255, 0.055);
            --tb-badge-text: #d1d5db;
            --tb-badge-border: rgba(255, 255, 255, 0.10);
        }

        .tb-stack {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .tb-card {
            background: var(--tb-card-bg);
            border: 1px solid var(--tb-card-border);
            border-radius: 1rem;
            box-shadow: var(--tb-card-shadow);
            padding: 1.25rem;
        }

        .tb-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--tb-soft-border);
        }

        .tb-summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        .tb-summary-card {
            background: var(--tb-soft-bg);
            border: 1px solid var(--tb-soft-border);
            border-radius: 0.875rem;
            padding: 1rem;
        }

        .tb-muted {
            color: var(--tb-muted);
            font-size: 0.82rem;
            font-weight: 650;
        }

        .tb-value {
            margin-top: 0.4rem;
            color: var(--tb-value);
            font-size: 1.15rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }

        .tb-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: var(--tb-badge-bg);
            color: var(--tb-badge-text);
            border: 1px solid var(--tb-badge-border);
            padding: 0.28rem 0.65rem;
            font-size: 0.78rem;
            font-weight: 750;
        }

        .tb-badge-success {
            background: var(--tb-badge-success-bg);
            color: var(--tb-success);
            border-color: var(--tb-badge-success-border);
        }

        .tb-badge-danger {
            background: var(--tb-badge-danger-bg);
            color: var(--tb-danger);
            border-color: var(--tb-badge-danger-border);
        }

        .tb-table-wrap {
            overflow-x: auto;
            border: 1px solid var(--tb-table-border);
            border-radius: 0.875rem;
        }

        .tb-table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .tb-table th {
            background: var(--tb-th-bg);
            color: var(--tb-th-text);
            font-weight: 750;
            padding: 0.85rem 1rem;
            text-align: right;
            white-space: nowrap;
        }

        .tb-table td {
            color: var(--tb-td-text);
            padding: 0.85rem 1rem;
            border-top: 1px solid var(--tb-table-border);
            vertical-align: top;
            line-height: 1.6;
        }

        .tb-table tbody tr:nth-child(even) td {
            background: var(--tb-row-even);
        }

        .tb-table tbody tr:hover td {
            background: var(--tb-row-hover);
        }

        .tb-number {
            direction: ltr;
            text-align: right;
            display: block;
            color: var(--tb-number);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .tb-empty {
            border: 1px dashed var(--tb-empty-border);
            border-radius: 0.875rem;
            padding: 2rem;
            color: var(--tb-muted);
            text-align: center;
            font-weight: 700;
        }

        @media (max-width: 900px) {
            .tb-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .tb-summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div dir="rtl" class="tb-page">
        <div class="tb-stack">
            <section class="tb-card">
                {{ $this->form }}

                <div class="tb-actions">
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
                <section class="tb-card">
                    <div class="tb-empty">يرجى اختيار العملة والفترة لعرض ميزان المراجعة</div>
                </section>
            @else
                <section class="tb-summary-grid">
                    <article class="tb-summary-card">
                        <div class="tb-muted">العملة</div>
                        <div class="tb-value" style="font-size: 1rem;">{{ $currencyLabel ?: '—' }}</div>
                    </article>
                    <article class="tb-summary-card">
                        <div class="tb-muted">عدد الحسابات</div>
                        <div class="tb-value" style="font-size: 1rem;">{{ $accountsCount }}</div>
                    </article>
                    <article class="tb-summary-card">
                        <div class="tb-muted">حالة الميزان</div>
                        <div class="tb-value" style="font-size: 1rem;">
                            <span class="tb-badge {{ $isBalanced ? 'tb-badge-success' : 'tb-badge-danger' }}">
                                {{ $isBalanced ? 'متوازن' : 'غير متوازن' }}
                            </span>
                        </div>
                    </article>
                    <article class="tb-summary-card">
                        <div class="tb-muted">إجمالي المدين</div>
                        <div class="tb-number">{!! $money($grandDebit) !!}</div>
                    </article>
                    <article class="tb-summary-card">
                        <div class="tb-muted">إجمالي الدائن</div>
                        <div class="tb-number">{!! $money($grandCredit) !!}</div>
                    </article>
                    <article class="tb-summary-card">
                        <div class="tb-muted">الفرق</div>
                        <div class="tb-number">{!! $money($difference) !!}</div>
                    </article>
                </section>

                <section class="tb-card">
                    @if (count($rows) === 0)
                        <div class="tb-empty">لا توجد بيانات ضمن الفترة المحددة</div>
                    @else
                        <div class="tb-table-wrap">
                            <table class="tb-table">
                                <thead>
                                    <tr>
                                        <th>كود الحساب</th>
                                        <th>اسم الحساب</th>
                                        <th>نوع الحساب</th>
                                        <th>العملة</th>
                                        <th>إجمالي المدين</th>
                                        <th>إجمالي الدائن</th>
                                        <th>الرصيد</th>
                                        <th>طبيعة الرصيد</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        <tr>
                                            <td dir="ltr">{{ $row['account_code'] ?: '-' }}</td>
                                            <td>{{ $row['account_name'] }}</td>
                                            <td>{{ $row['account_type_name'] ?: '-' }}</td>
                                            <td>
                                                <span class="tb-badge" dir="ltr">{{ $row['currency_label'] ?: '-' }}</span>
                                            </td>
                                            <td>
                                                @if ($row['total_debit'] > 0)
                                                    <span class="tb-number">{!! $money($row['total_debit']) !!}</span>
                                                @else
                                                    <span class="tb-number" style="color: var(--tb-muted);">-</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($row['total_credit'] > 0)
                                                    <span class="tb-number">{!! $money($row['total_credit']) !!}</span>
                                                @else
                                                    <span class="tb-number" style="color: var(--tb-muted);">-</span>
                                                @endif
                                            </td>
                                            <td class="tb-number">{!! $money($row['balance']) !!}</td>
                                            <td>{{ $row['nature'] }}</td>
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
