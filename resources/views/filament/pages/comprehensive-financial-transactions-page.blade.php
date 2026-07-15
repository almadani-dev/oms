@php
    $money = fn ($value) => \App\Helpers\NumberHelper::bigComma($value) ?? '0.00';
@endphp

<x-filament-panels::page>
    <style>
        .cft-page {
            --cft-card-bg: #ffffff;
            --cft-card-border: #e5e7eb;
            --cft-card-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.04);
            --cft-soft-bg: #f9fafb;
            --cft-soft-border: #e5e7eb;
            --cft-heading: #111827;
            --cft-value: #111827;
            --cft-muted: #6b7280;
            --cft-th-bg: #f3f4f6;
            --cft-th-text: #374151;
            --cft-td-text: #374151;
            --cft-table-border: #e5e7eb;
            --cft-row-even: rgba(0, 0, 0, 0.02);
            --cft-row-hover: rgba(0, 0, 0, 0.04);
            --cft-number: #111827;
            --cft-empty-border: #d1d5db;
            --cft-success: #16a34a;
            --cft-danger: #dc2626;
            --cft-badge-success-bg: #dcfce7;
            --cft-badge-success-border: #bbf7d0;
            --cft-badge-danger-bg: #fee2e2;
            --cft-badge-danger-border: #fecaca;
            --cft-badge-bg: #f3f4f6;
            --cft-badge-text: #374151;
            --cft-badge-border: #e5e7eb;

            direction: rtl;
            width: 100%;
        }

        .dark .cft-page {
            --cft-card-bg: rgba(17, 24, 39, 0.76);
            --cft-card-border: rgba(255, 255, 255, 0.10);
            --cft-card-shadow: 0 8px 24px rgba(0, 0, 0, 0.14);
            --cft-soft-bg: rgba(31, 41, 55, 0.55);
            --cft-soft-border: rgba(255, 255, 255, 0.08);
            --cft-heading: #ffffff;
            --cft-value: #ffffff;
            --cft-muted: #9ca3af;
            --cft-th-bg: rgba(255, 255, 255, 0.06);
            --cft-th-text: #e5e7eb;
            --cft-td-text: #d1d5db;
            --cft-table-border: rgba(255, 255, 255, 0.10);
            --cft-row-even: rgba(255, 255, 255, 0.018);
            --cft-row-hover: rgba(255, 255, 255, 0.035);
            --cft-number: #f9fafb;
            --cft-empty-border: rgba(255, 255, 255, 0.16);
            --cft-success: #4ade80;
            --cft-danger: #f87171;
            --cft-badge-success-bg: rgba(74, 222, 128, 0.14);
            --cft-badge-success-border: rgba(74, 222, 128, 0.28);
            --cft-badge-danger-bg: rgba(248, 113, 113, 0.14);
            --cft-badge-danger-border: rgba(248, 113, 113, 0.28);
            --cft-badge-bg: rgba(255, 255, 255, 0.055);
            --cft-badge-text: #d1d5db;
            --cft-badge-border: rgba(255, 255, 255, 0.10);
        }

        .cft-stack {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .cft-card {
            background: var(--cft-card-bg);
            border: 1px solid var(--cft-card-border);
            border-radius: 1rem;
            box-shadow: var(--cft-card-shadow);
            padding: 1.25rem;
        }

        .cft-card-title {
            color: var(--cft-heading);
            font-size: 0.95rem;
            font-weight: 800;
            margin-bottom: 1rem;
        }

        .cft-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--cft-soft-border);
        }

        .cft-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .cft-summary-card {
            background: var(--cft-soft-bg);
            border: 1px solid var(--cft-soft-border);
            border-radius: 0.875rem;
            padding: 1rem;
        }

        .cft-muted {
            color: var(--cft-muted);
            font-size: 0.82rem;
            font-weight: 650;
        }

        .cft-value {
            margin-top: 0.4rem;
            color: var(--cft-value);
            font-size: 1rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }

        .cft-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: var(--cft-badge-bg);
            color: var(--cft-badge-text);
            border: 1px solid var(--cft-badge-border);
            padding: 0.28rem 0.65rem;
            font-size: 0.78rem;
            font-weight: 750;
        }

        .cft-badge-success {
            background: var(--cft-badge-success-bg);
            color: var(--cft-success);
            border-color: var(--cft-badge-success-border);
        }

        .cft-badge-danger {
            background: var(--cft-badge-danger-bg);
            color: var(--cft-danger);
            border-color: var(--cft-badge-danger-border);
        }

        .cft-filters-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.25rem;
        }

        .cft-table-wrap {
            overflow-x: auto;
            border: 1px solid var(--cft-table-border);
            border-radius: 0.875rem;
        }

        .cft-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .cft-table-detail {
            min-width: 1250px;
        }

        .cft-table th {
            background: var(--cft-th-bg);
            color: var(--cft-th-text);
            font-weight: 750;
            padding: 0.75rem 0.9rem;
            text-align: right;
            white-space: nowrap;
        }

        .cft-table td {
            color: var(--cft-td-text);
            padding: 0.75rem 0.9rem;
            border-top: 1px solid var(--cft-table-border);
            vertical-align: top;
            line-height: 1.6;
        }

        .cft-table tbody tr:nth-child(even) td {
            background: var(--cft-row-even);
        }

        .cft-table tbody tr:hover td {
            background: var(--cft-row-hover);
        }

        .cft-number {
            direction: ltr;
            text-align: right;
            display: block;
            color: var(--cft-number);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .cft-currency-lines {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }

        .cft-empty {
            border: 1px dashed var(--cft-empty-border);
            border-radius: 0.875rem;
            padding: 2rem;
            color: var(--cft-muted);
            text-align: center;
            font-weight: 700;
        }

        .cft-clamp {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            white-space: normal;
            word-break: break-word;
        }

        @media (max-width: 1100px) {
            .cft-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .cft-summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div dir="rtl" class="cft-page">
        <div class="cft-stack">
            <section class="cft-card">
                {{ $this->form }}

                <div class="cft-actions">
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
                <section class="cft-card">
                    <div class="cft-empty">يرجى اختيار الفترة لعرض تقرير الحركات المالية الشامل</div>
                </section>
            @elseif (count($rows) === 0)
                <section class="cft-card">
                    <div class="cft-empty">لا توجد حركات مالية ضمن الفترة المحددة</div>
                </section>
            @else
                {{-- A) General summary --}}
                <section class="cft-summary-grid">
                    <article class="cft-summary-card">
                        <div class="cft-muted">الفترة</div>
                        <div class="cft-value" dir="ltr" style="text-align: right;">
                            {{ $appliedDateFrom }} → {{ $appliedDateTo }}
                        </div>
                    </article>
                    <article class="cft-summary-card">
                        <div class="cft-muted">عدد المعاملات</div>
                        <div class="cft-value">{{ $transactionCount }}</div>
                    </article>
                    <article class="cft-summary-card">
                        <div class="cft-muted">عدد بنود القيود</div>
                        <div class="cft-value">{{ $lineCount }}</div>
                    </article>
                    <article class="cft-summary-card">
                        <div class="cft-muted">عدد العملات الظاهرة</div>
                        <div class="cft-value">{{ $currenciesCount }}</div>
                    </article>
                </section>

                @if (count($appliedFilterLabels) > 0)
                    <section class="cft-card">
                        <div class="cft-muted">الفلاتر المطبقة</div>
                        <div class="cft-filters-summary">
                            @foreach ($appliedFilterLabels as $label => $value)
                                <span class="cft-badge">{{ $label }}: {{ $value }}</span>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- B) Summary by currency — totals are never blended across currencies --}}
                <section class="cft-card">
                    <div class="cft-card-title">الملخص حسب العملة</div>
                    <div class="cft-table-wrap">
                        <table class="cft-table">
                            <thead>
                                <tr>
                                    <th>العملة</th>
                                    <th>إجمالي المدين</th>
                                    <th>إجمالي الدائن</th>
                                    <th>الفرق</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($currencySummaries as $summary)
                                    <tr>
                                        <td><span class="cft-badge" dir="ltr">{{ $summary['currency'] }}</span></td>
                                        <td><span class="cft-number">{!! $money($summary['total_debit']) !!}</span></td>
                                        <td><span class="cft-number">{!! $money($summary['total_credit']) !!}</span></td>
                                        <td><span class="cft-number">{!! $money($summary['difference']) !!}</span></td>
                                        <td>
                                            <span class="cft-badge {{ $summary['is_balanced'] ? 'cft-badge-success' : 'cft-badge-danger' }}">
                                                {{ $summary['is_balanced'] ? 'متوازن' : 'غير متوازن' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                {{-- C) Statistics by transaction category (تصنيف المعاملة) — distinct transactions, line-based totals per currency --}}
                <section class="cft-card">
                    <div class="cft-card-title">إحصائيات حسب تصنيف المعاملة</div>
                    <div class="cft-table-wrap">
                        <table class="cft-table">
                            <thead>
                                <tr>
                                    <th>اسم التصنيف</th>
                                    <th>عدد المعاملات</th>
                                    <th>إجمالي المدين حسب العملة</th>
                                    <th>إجمالي الدائن حسب العملة</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($categorySummaries as $summary)
                                    <tr>
                                        <td>{{ $summary['name'] }}</td>
                                        <td>{{ $summary['transaction_count'] }}</td>
                                        <td>
                                            <div class="cft-currency-lines">
                                                @foreach ($summary['currencies'] as $currencyTotals)
                                                    <span class="cft-number">{!! $money($currencyTotals['total_debit']) !!} {{ $currencyTotals['currency'] }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td>
                                            <div class="cft-currency-lines">
                                                @foreach ($summary['currencies'] as $currencyTotals)
                                                    <span class="cft-number">{!! $money($currencyTotals['total_credit']) !!} {{ $currencyTotals['currency'] }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                {{-- D) Statistics by transaction type (نوع المعاملة) — distinct transactions, line-based totals per currency --}}
                <section class="cft-card">
                    <div class="cft-card-title">إحصائيات حسب نوع المعاملة</div>
                    <div class="cft-table-wrap">
                        <table class="cft-table">
                            <thead>
                                <tr>
                                    <th>اسم النوع</th>
                                    <th>عدد المعاملات</th>
                                    <th>إجمالي المدين حسب العملة</th>
                                    <th>إجمالي الدائن حسب العملة</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($typeSummaries as $summary)
                                    <tr>
                                        <td>{{ $summary['name'] }}</td>
                                        <td>{{ $summary['transaction_count'] }}</td>
                                        <td>
                                            <div class="cft-currency-lines">
                                                @foreach ($summary['currencies'] as $currencyTotals)
                                                    <span class="cft-number">{!! $money($currencyTotals['total_debit']) !!} {{ $currencyTotals['currency'] }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td>
                                            <div class="cft-currency-lines">
                                                @foreach ($summary['currencies'] as $currencyTotals)
                                                    <span class="cft-number">{!! $money($currencyTotals['total_credit']) !!} {{ $currencyTotals['currency'] }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                {{-- Detail table: one row per transaction line --}}
                <section class="cft-card">
                    <div class="cft-card-title">تفاصيل الحركات المالية</div>
                    <div class="cft-table-wrap">
                        <table class="cft-table cft-table-detail">
                            <thead>
                                <tr>
                                    <th>التاريخ</th>
                                    <th>رقم القيد / رقم المعاملة</th>
                                    <th>تصنيف المعاملة</th>
                                    <th>نوع المعاملة</th>
                                    <th>الوصف / البيان</th>
                                    <th>وصف العملية المالية</th>
                                    <th>دور سطر القيد</th>
                                    <th>وصف سطر القيد</th>
                                    <th>الحساب</th>
                                    <th>نوع الحساب</th>
                                    <th>المشروع</th>
                                    <th>العملة</th>
                                    <th>مدين</th>
                                    <th>دائن</th>
                                    <th>المستخدم</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td dir="ltr" style="text-align: right; white-space: nowrap;">{{ $row['date'] }}</td>
                                        <td>{{ $row['reference'] }}</td>
                                        <td>{{ $row['category'] }}</td>
                                        <td>{{ $row['type'] }}</td>
                                        <td style="min-width: 220px;">{{ $row['description'] }}</td>
                                        <td style="min-width: 200px;">
                                            <div class="cft-clamp" title="{{ $row['transaction_description'] }}">{{ $row['transaction_description'] }}</div>
                                        </td>
                                        <td><span class="cft-badge">{{ $row['line_role_label'] }}</span></td>
                                        <td style="min-width: 200px;">
                                            <div class="cft-clamp" title="{{ $row['line_description'] }}">{{ $row['line_description'] }}</div>
                                        </td>
                                        <td>{{ $row['account'] }}</td>
                                        <td>{{ $row['account_type'] }}</td>
                                        <td>{{ $row['project'] }}</td>
                                        <td><span class="cft-badge" dir="ltr">{{ $row['currency'] }}</span></td>
                                        <td>
                                            @if ($row['debit'] > 0)
                                                <span class="cft-number">{!! $money($row['debit']) !!}</span>
                                            @else
                                                <span class="cft-number" style="color: var(--cft-muted);">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($row['credit'] > 0)
                                                <span class="cft-number">{!! $money($row['credit']) !!}</span>
                                            @else
                                                <span class="cft-number" style="color: var(--cft-muted);">-</span>
                                            @endif
                                        </td>
                                        <td>{{ $row['created_by'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
