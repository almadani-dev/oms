@php
    /**
     * "كشف حساب الأسرة".
     *
     * Renders ONLY the snapshot the page built on "عرض" ($report). It performs
     * no query, no aggregation and no formatting decision of its own beyond
     * presentation — every figure here came out of
     * MuwakhaFamilyAccountStatementService, which is the same array both
     * exports render.
     *
     * There is deliberately no opening-balance card, no running-balance column
     * and no grand-total row: this report has no balance concept at all, and
     * totals exist only inside a currency section.
     */
    $money = fn ($value) => \App\Helpers\NumberHelper::bigComma($value) ?? '0.00';
    $dateTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i') : '—';
    $family = $this->getFamily();
@endphp

<x-filament-panels::page>
    <style>
        .mfs-page {
            --mfs-card-bg: #ffffff;
            --mfs-card-border: #e5e7eb;
            --mfs-card-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.04);
            --mfs-soft-bg: #f9fafb;
            --mfs-soft-border: #e5e7eb;
            --mfs-heading: #111827;
            --mfs-value: #111827;
            --mfs-muted: #6b7280;
            --mfs-th-bg: #f3f4f6;
            --mfs-th-text: #374151;
            --mfs-td-text: #374151;
            --mfs-table-border: #e5e7eb;
            --mfs-row-even: rgba(0, 0, 0, 0.02);
            --mfs-row-hover: rgba(0, 0, 0, 0.04);
            --mfs-number: #111827;
            --mfs-empty-border: #d1d5db;
            --mfs-badge-bg: #f3f4f6;
            --mfs-badge-text: #374151;
            --mfs-badge-border: #e5e7eb;
            --mfs-danger-bg: #fee2e2;
            --mfs-danger-text: #b91c1c;
            --mfs-danger-border: #fecaca;

            direction: rtl;
            width: 100%;
        }

        .dark .mfs-page {
            --mfs-card-bg: rgba(17, 24, 39, 0.76);
            --mfs-card-border: rgba(255, 255, 255, 0.10);
            --mfs-card-shadow: 0 8px 24px rgba(0, 0, 0, 0.14);
            --mfs-soft-bg: rgba(31, 41, 55, 0.55);
            --mfs-soft-border: rgba(255, 255, 255, 0.08);
            --mfs-heading: #ffffff;
            --mfs-value: #ffffff;
            --mfs-muted: #9ca3af;
            --mfs-th-bg: rgba(255, 255, 255, 0.06);
            --mfs-th-text: #e5e7eb;
            --mfs-td-text: #d1d5db;
            --mfs-table-border: rgba(255, 255, 255, 0.10);
            --mfs-row-even: rgba(255, 255, 255, 0.018);
            --mfs-row-hover: rgba(255, 255, 255, 0.035);
            --mfs-number: #f9fafb;
            --mfs-empty-border: rgba(255, 255, 255, 0.16);
            --mfs-badge-bg: rgba(255, 255, 255, 0.055);
            --mfs-badge-text: #d1d5db;
            --mfs-badge-border: rgba(255, 255, 255, 0.10);
            --mfs-danger-bg: rgba(239, 68, 68, 0.14);
            --mfs-danger-text: #fca5a5;
            --mfs-danger-border: rgba(248, 113, 113, 0.26);
        }

        .mfs-stack {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .mfs-card {
            background: var(--mfs-card-bg);
            border: 1px solid var(--mfs-card-border);
            border-radius: 1rem;
            box-shadow: var(--mfs-card-shadow);
            padding: 1.25rem;
        }

        .mfs-section-title {
            color: var(--mfs-heading);
            font-size: 1rem;
            font-weight: 800;
            margin-bottom: 1rem;
        }

        .mfs-currency-title {
            color: var(--mfs-heading);
            font-size: 1.05rem;
            font-weight: 800;
            margin-bottom: 0.85rem;
        }

        .mfs-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--mfs-soft-border);
        }

        .mfs-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .mfs-tile {
            background: var(--mfs-soft-bg);
            border: 1px solid var(--mfs-soft-border);
            border-radius: 0.875rem;
            padding: 1rem;
        }

        .mfs-muted {
            color: var(--mfs-muted);
            font-size: 0.82rem;
            font-weight: 650;
        }

        .mfs-value {
            margin-top: 0.4rem;
            color: var(--mfs-value);
            font-size: 1rem;
            font-weight: 800;
        }

        .mfs-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: var(--mfs-badge-bg);
            color: var(--mfs-badge-text);
            border: 1px solid var(--mfs-badge-border);
            padding: 0.28rem 0.65rem;
            font-size: 0.78rem;
            font-weight: 750;
        }

        .mfs-badge-deleted {
            background: var(--mfs-danger-bg);
            color: var(--mfs-danger-text);
            border-color: var(--mfs-danger-border);
        }

        .mfs-table-wrap {
            overflow-x: auto;
            border: 1px solid var(--mfs-table-border);
            border-radius: 0.875rem;
        }

        .mfs-table {
            width: 100%;
            min-width: 960px;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .mfs-table th {
            background: var(--mfs-th-bg);
            color: var(--mfs-th-text);
            font-weight: 750;
            padding: 0.85rem 1rem;
            text-align: right;
            white-space: nowrap;
        }

        .mfs-table td {
            color: var(--mfs-td-text);
            padding: 0.85rem 1rem;
            border-top: 1px solid var(--mfs-table-border);
            vertical-align: top;
            line-height: 1.6;
        }

        .mfs-table tbody tr:nth-child(even) td {
            background: var(--mfs-row-even);
        }

        .mfs-table tbody tr:hover td {
            background: var(--mfs-row-hover);
        }

        .mfs-number {
            direction: ltr;
            text-align: right;
            display: block;
            color: var(--mfs-number);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .mfs-totals {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
        }

        .mfs-total {
            flex: 1 1 220px;
            background: var(--mfs-soft-bg);
            border: 1px solid var(--mfs-soft-border);
            border-radius: 0.875rem;
            padding: 0.9rem 1rem;
        }

        .mfs-total .mfs-number {
            margin-top: 0.35rem;
            font-size: 1.1rem;
            font-weight: 800;
        }

        .mfs-empty {
            border: 1px dashed var(--mfs-empty-border);
            border-radius: 0.875rem;
            padding: 2rem;
            color: var(--mfs-muted);
            text-align: center;
            font-weight: 700;
        }

        .mfs-clamp {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            white-space: normal;
            word-break: break-word;
        }

        @media (max-width: 1100px) {
            .mfs-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .mfs-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div dir="rtl" class="mfs-page">
        <div class="mfs-stack">

            {{-- بيانات الأسرة — always visible, with or without a submitted report. --}}
            <section class="mfs-card">
                <div class="mfs-section-title">بيانات الأسرة</div>
                <div class="mfs-grid">
                    <article class="mfs-tile">
                        <div class="mfs-muted">اسم الشهيد</div>
                        <div class="mfs-value">{{ $family->martyr_name }}</div>
                    </article>
                    <article class="mfs-tile">
                        <div class="mfs-muted">رقم هوية الشهيد</div>
                        <div class="mfs-value" dir="ltr">{{ $family->martyr_national_id ?: '—' }}</div>
                    </article>
                    <article class="mfs-tile">
                        <div class="mfs-muted">اسم الوصي</div>
                        <div class="mfs-value">{{ $family->guardian_name ?: '—' }}</div>
                    </article>
                    <article class="mfs-tile">
                        <div class="mfs-muted">رقم الجوال</div>
                        <div class="mfs-value" dir="ltr">{{ $family->guardian_phone ?: '—' }}</div>
                    </article>
                </div>
            </section>

            {{-- الفلاتر — nothing loads until "عرض" is pressed. --}}
            <section class="mfs-card">
                <div class="mfs-section-title">الفلاتر</div>
                {{ $this->form }}

                <div class="mfs-actions">
                    <x-filament::button
                        type="button"
                        icon="heroicon-o-magnifying-glass"
                        wire:click="showReport"
                    >
                        عرض
                    </x-filament::button>
                </div>
            </section>

            @if (! $hasSubmitted || $report === null)
                <section class="mfs-card">
                    <div class="mfs-empty">يرجى تحديد الفلاتر ثم الضغط على عرض</div>
                </section>
            @else
                {{-- نطاق التقرير المطبق --}}
                <section class="mfs-card">
                    <div class="mfs-section-title">نطاق التقرير</div>
                    <div class="mfs-grid">
                        @foreach ($report['filter_labels'] as $label => $value)
                            <article class="mfs-tile">
                                <div class="mfs-muted">{{ $label }}</div>
                                <div class="mfs-value">{{ $value }}</div>
                            </article>
                        @endforeach
                    </div>
                </section>

                {{--
                    الحسابات المشمولة — includes previous and soft-deleted mapped
                    Accounts, because their historical movements are part of this
                    report. This is the deliberate opposite of the Family View
                    page, which hides deleted Accounts as an operational rule.
                --}}
                <section class="mfs-card">
                    <div class="mfs-section-title">الحسابات المشمولة</div>

                    @if (empty($report['accounts']))
                        <div class="mfs-empty">لا توجد حسابات مرتبطة بهذه الأسرة</div>
                    @else
                        <div class="mfs-table-wrap">
                            <table class="mfs-table">
                                <thead>
                                    <tr>
                                        <th>اسم الحساب</th>
                                        <th>العملة</th>
                                        <th>رقم الحساب</th>
                                        <th>نوع البنك / طريقة الحساب</th>
                                        <th>نوع الحساب</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($report['accounts'] as $account)
                                        <tr>
                                            <td>{{ $account['name'] }}</td>
                                            <td>{{ $account['currency_name'] ?: '—' }}</td>
                                            <td dir="ltr">{{ $account['account_code'] ?: '—' }}</td>
                                            <td>{{ $account['bank_type_name'] ?: '—' }}</td>
                                            <td>{{ $account['account_type_name'] ?: '—' }}</td>
                                            <td>
                                                <span class="mfs-badge @if ($account['is_deleted']) mfs-badge-deleted @endif">
                                                    {{ $account['status_label'] }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                {{--
                    الحركات المالية — one section per CURRENCY. Several mapped
                    Accounts sharing a currency are merged into one chronological
                    section; each row names the Account it belongs to. Totals live
                    inside a section only, so no cross-currency figure exists.
                --}}
                @if (empty($report['currency_groups']))
                    <section class="mfs-card">
                        <div class="mfs-section-title">الحركات المالية</div>
                        <div class="mfs-empty">{{ \App\Services\Muwakha\MuwakhaFamilyAccountStatementService::EMPTY_NOTICE }}</div>
                    </section>
                @else
                    @foreach ($report['currency_groups'] as $group)
                        <section class="mfs-card">
                            <div class="mfs-currency-title">العملة: {{ $group['currency_label'] }}</div>

                            <div class="mfs-table-wrap">
                                <table class="mfs-table">
                                    <thead>
                                        <tr>
                                            <th>التاريخ</th>
                                            <th>رقم المعاملة</th>
                                            <th>نوع الحركة</th>
                                            <th>البيان / الملاحظات</th>
                                            <th>الحساب</th>
                                            <th>مدين</th>
                                            <th>دائن</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($group['rows'] as $row)
                                            <tr>
                                                <td>{{ $dateTime($row['date']) }}</td>
                                                <td dir="ltr">{{ $row['transaction_number'] }}</td>
                                                <td>{{ $row['type_name'] }}</td>
                                                <td style="min-width: 220px;">
                                                    <div class="mfs-clamp" title="{{ $row['description'] }}">{{ $row['description'] }}</div>
                                                </td>
                                                <td style="min-width: 200px;">
                                                    <div class="mfs-clamp" title="{{ $row['account_label'] }}">{{ $row['account_label'] }}</div>
                                                </td>
                                                <td>
                                                    @if ($row['debit'] > 0)
                                                        <span class="mfs-number">{!! $money($row['debit']) !!}</span>
                                                    @else
                                                        <span class="mfs-number" style="color: var(--mfs-muted);">-</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if ($row['credit'] > 0)
                                                        <span class="mfs-number">{!! $money($row['credit']) !!}</span>
                                                    @else
                                                        <span class="mfs-number" style="color: var(--mfs-muted);">-</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="mfs-totals">
                                <article class="mfs-total">
                                    <div class="mfs-muted">إجمالي المدين</div>
                                    <div class="mfs-number">{!! $money($group['total_debit']) !!}</div>
                                </article>
                                <article class="mfs-total">
                                    <div class="mfs-muted">إجمالي الدائن</div>
                                    <div class="mfs-number">{!! $money($group['total_credit']) !!}</div>
                                </article>
                            </div>
                        </section>
                    @endforeach
                @endif
            @endif

        </div>
    </div>
</x-filament-panels::page>
