@php
    $money = fn ($value) => \App\Helpers\NumberHelper::bigComma($value) ?? '0.00';
    $percent = fn ($value) => $value === null ? '—' : number_format((float) $value, 2) . '%';
    $signClass = function (float $value) {
        if ($value > 0.0) {
            return 'donor-rpt-number donor-rpt-number-success';
        }

        if ($value < 0.0) {
            return 'donor-rpt-number donor-rpt-number-danger';
        }

        return 'donor-rpt-number';
    };
@endphp

<x-filament-panels::page>
    <style>
        .donor-rpt-page {
            --dr-card-bg: #ffffff;
            --dr-card-border: #e5e7eb;
            --dr-card-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.04);
            --dr-soft-bg: #f9fafb;
            --dr-soft-border: #e5e7eb;
            --dr-heading: #111827;
            --dr-value: #111827;
            --dr-muted: #6b7280;
            --dr-th-bg: #f3f4f6;
            --dr-th-text: #374151;
            --dr-td-text: #374151;
            --dr-table-border: #e5e7eb;
            --dr-row-even: rgba(0, 0, 0, 0.02);
            --dr-row-hover: rgba(0, 0, 0, 0.04);
            --dr-number: #111827;
            --dr-empty-border: #d1d5db;
            --dr-success: #16a34a;
            --dr-danger: #dc2626;
            --dr-badge-bg: #f3f4f6;
            --dr-badge-text: #374151;
            --dr-badge-border: #e5e7eb;

            direction: rtl;
            width: 100%;
        }

        .dark .donor-rpt-page {
            --dr-card-bg: rgba(17, 24, 39, 0.76);
            --dr-card-border: rgba(255, 255, 255, 0.10);
            --dr-card-shadow: 0 8px 24px rgba(0, 0, 0, 0.14);
            --dr-soft-bg: rgba(31, 41, 55, 0.55);
            --dr-soft-border: rgba(255, 255, 255, 0.08);
            --dr-heading: #ffffff;
            --dr-value: #ffffff;
            --dr-muted: #9ca3af;
            --dr-th-bg: rgba(255, 255, 255, 0.06);
            --dr-th-text: #e5e7eb;
            --dr-td-text: #d1d5db;
            --dr-table-border: rgba(255, 255, 255, 0.10);
            --dr-row-even: rgba(255, 255, 255, 0.018);
            --dr-row-hover: rgba(255, 255, 255, 0.035);
            --dr-number: #f9fafb;
            --dr-empty-border: rgba(255, 255, 255, 0.16);
            --dr-success: #4ade80;
            --dr-danger: #f87171;
            --dr-badge-bg: rgba(255, 255, 255, 0.055);
            --dr-badge-text: #d1d5db;
            --dr-badge-border: rgba(255, 255, 255, 0.10);
        }

        .donor-rpt-stack {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .donor-rpt-card {
            background: var(--dr-card-bg);
            border: 1px solid var(--dr-card-border);
            border-radius: 1rem;
            box-shadow: var(--dr-card-shadow);
            padding: 1.25rem;
        }

        .donor-rpt-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--dr-soft-border);
        }

        .donor-rpt-section-title {
            color: var(--dr-heading);
            font-size: 1.05rem;
            font-weight: 800;
            margin-bottom: 0.9rem;
        }

        .donor-rpt-subtitle {
            color: var(--dr-muted);
            font-size: 0.82rem;
            font-weight: 700;
            margin: 0.9rem 0 0.5rem;
        }

        .donor-rpt-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .donor-rpt-summary-card {
            background: var(--dr-soft-bg);
            border: 1px solid var(--dr-soft-border);
            border-radius: 0.875rem;
            padding: 1rem;
        }

        .donor-rpt-muted {
            color: var(--dr-muted);
            font-size: 0.82rem;
            font-weight: 650;
        }

        .donor-rpt-value {
            margin-top: 0.4rem;
            color: var(--dr-value);
            font-size: 1.05rem;
            font-weight: 800;
        }

        .donor-rpt-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: var(--dr-badge-bg);
            color: var(--dr-badge-text);
            border: 1px solid var(--dr-badge-border);
            padding: 0.28rem 0.65rem;
            font-size: 0.78rem;
            font-weight: 750;
        }

        .donor-rpt-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.9rem;
        }

        .donor-rpt-table-wrap {
            overflow-x: auto;
            border: 1px solid var(--dr-table-border);
            border-radius: 0.875rem;
        }

        .donor-rpt-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .donor-rpt-table-wide {
            min-width: 1100px;
        }

        .donor-rpt-table th {
            background: var(--dr-th-bg);
            color: var(--dr-th-text);
            font-weight: 750;
            padding: 0.85rem 1rem;
            text-align: right;
            white-space: nowrap;
        }

        .donor-rpt-table td {
            color: var(--dr-td-text);
            padding: 0.85rem 1rem;
            border-top: 1px solid var(--dr-table-border);
            vertical-align: top;
            line-height: 1.6;
        }

        .donor-rpt-table tbody tr:nth-child(even) td {
            background: var(--dr-row-even);
        }

        .donor-rpt-table tbody tr:hover td {
            background: var(--dr-row-hover);
        }

        .donor-rpt-number {
            direction: ltr;
            text-align: right;
            display: block;
            color: var(--dr-number);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .donor-rpt-number-success {
            color: var(--dr-success);
            font-weight: 800;
        }

        .donor-rpt-number-danger {
            color: var(--dr-danger);
            font-weight: 800;
        }

        .donor-rpt-number-code {
            color: var(--dr-muted);
            font-size: 0.72rem;
            font-weight: 700;
        }

        .donor-rpt-empty {
            border: 1px dashed var(--dr-empty-border);
            border-radius: 0.875rem;
            padding: 2rem;
            color: var(--dr-muted);
            text-align: center;
            font-weight: 700;
        }

        @media (max-width: 1100px) {
            .donor-rpt-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .donor-rpt-summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div dir="rtl" class="donor-rpt-page">
        <div class="donor-rpt-stack">
            <section class="donor-rpt-card">
                {{ $this->form }}

                <div class="donor-rpt-actions">
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
                <section class="donor-rpt-card">
                    <div class="donor-rpt-empty">يرجى اختيار الجهة المانحة ثم الضغط على عرض</div>
                </section>
            @else
                {{-- A) Donor summary --}}
                <section class="donor-rpt-card">
                    <div class="donor-rpt-section-title">بيانات الجهة المانحة</div>
                    <div class="donor-rpt-summary-grid">
                        <article class="donor-rpt-summary-card">
                            <div class="donor-rpt-muted">الجهة المانحة</div>
                            <div class="donor-rpt-value">{{ $report['donor_name'] }}</div>
                        </article>
                        <article class="donor-rpt-summary-card">
                            <div class="donor-rpt-muted">نوع الجهة</div>
                            <div class="donor-rpt-value">{{ $report['donor_type'] ?: '—' }}</div>
                        </article>
                        <article class="donor-rpt-summary-card">
                            <div class="donor-rpt-muted">البريد الإلكتروني / الجوال</div>
                            <div class="donor-rpt-value" style="font-size: 0.9rem;">
                                <span dir="ltr">{{ $report['donor_email'] ?: '—' }}</span><br>
                                <span dir="ltr">{{ $report['donor_mobile'] ?: '—' }}</span>
                            </div>
                        </article>
                        <article class="donor-rpt-summary-card">
                            <div class="donor-rpt-muted">عدد المشاريع المرتبطة (بعد التصفية)</div>
                            <div class="donor-rpt-value donor-rpt-number" style="text-align: right;">{{ $report['projects_count'] }}</div>
                        </article>
                    </div>

                    @if (! empty($report['applied_filters']))
                        <div class="donor-rpt-filters">
                            @foreach ($report['applied_filters'] as [$label, $value])
                                <span class="donor-rpt-badge">{{ $label }}: {{ $value }}</span>
                            @endforeach
                        </div>
                    @endif
                </section>

                {{-- B) Funding summary (cost-side) — immediately after donor info --}}
                <section class="donor-rpt-card">
                    <div class="donor-rpt-section-title">ملخص التمويل حسب العملة</div>

                    @if (empty($report['cost_summary']))
                        <div class="donor-rpt-empty">لا توجد بيانات تكاليف</div>
                    @else
                        <div class="donor-rpt-table-wrap">
                            <table class="donor-rpt-table">
                                <thead>
                                    <tr>
                                        <th>العملة</th>
                                        <th>إجمالي المبالغ المطلوبة</th>
                                        <th>إجمالي المبالغ المستلمة</th>
                                        <th>الفائض/العجز</th>
                                        <th>نسبة التحصيل</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($report['cost_summary'] as $row)
                                        <tr>
                                            <td><span class="donor-rpt-badge" dir="ltr">{{ $row['currency_code'] }}</span></td>
                                            <td><span class="donor-rpt-number">{!! $money($row['planned']) !!}</span></td>
                                            <td><span class="donor-rpt-number">{!! $money($row['received']) !!}</span></td>
                                            <td><span class="{{ $signClass($row['surplus']) }}">{!! $money($row['surplus']) !!}</span></td>
                                            <td><span class="donor-rpt-number">{{ $percent($row['collection_percentage']) }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                {{-- C) Disbursement / execution summary by currency — two grains, never mixed --}}
                <section class="donor-rpt-card">
                    <div class="donor-rpt-section-title">الملخص المالي حسب العملة</div>

                    <div class="donor-rpt-subtitle">جانب الصرف — بعملة المصدر (المبالغ المرصودة والخصومات)</div>
                    @if (empty($report['disb_source_summary']))
                        <div class="donor-rpt-empty">لا توجد عمليات صرف</div>
                    @else
                        <div class="donor-rpt-table-wrap">
                            <table class="donor-rpt-table">
                                <thead>
                                    <tr>
                                        <th>العملة</th>
                                        <th>إجمالي المبالغ المصروفة / المرصودة</th>
                                        <th>إجمالي الخصم الإداري</th>
                                        <th>إجمالي خصم التحويل</th>
                                        <th>المبلغ بعد الخصومات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($report['disb_source_summary'] as $row)
                                        <tr>
                                            <td><span class="donor-rpt-badge" dir="ltr">{{ $row['currency_code'] }}</span></td>
                                            <td><span class="donor-rpt-number">{!! $money($row['original']) !!}</span></td>
                                            <td><span class="donor-rpt-number">{!! $money($row['admin_deduction']) !!}</span></td>
                                            <td><span class="donor-rpt-number">{!! $money($row['transfer_deduction']) !!}</span></td>
                                            <td><span class="donor-rpt-number">{!! $money($row['after_deductions']) !!}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="donor-rpt-subtitle">جانب التنفيذ — بعملة الصرف النهائية</div>
                    @if (empty($report['disb_final_summary']))
                        <div class="donor-rpt-empty">لا توجد مبالغ صرف نهائية</div>
                    @else
                        <div class="donor-rpt-table-wrap">
                            <table class="donor-rpt-table">
                                <thead>
                                    <tr>
                                        <th>العملة</th>
                                        <th>صافي مبلغ الصرف / المبلغ النهائي</th>
                                        <th>إجمالي مبالغ التنفيذ المدفوعة</th>
                                        <th>المتبقي من الصرف</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($report['disb_final_summary'] as $row)
                                        <tr>
                                            <td><span class="donor-rpt-badge" dir="ltr">{{ $row['currency_code'] }}</span></td>
                                            <td><span class="donor-rpt-number">{!! $money($row['final']) !!}</span></td>
                                            <td><span class="donor-rpt-number">{!! $money($row['execution_paid']) !!}</span></td>
                                            <td><span class="{{ $signClass($row['remaining']) }}">{!! $money($row['remaining']) !!}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                {{-- D) Projects table --}}
                <section class="donor-rpt-card">
                    <div class="donor-rpt-section-title">مشاريع الجهة المانحة ({{ $report['projects_count'] }})</div>
                    @if (empty($report['projects']))
                        <div class="donor-rpt-empty">لا توجد مشاريع مطابقة للفلاتر المحددة</div>
                    @else
                        <div class="donor-rpt-table-wrap">
                            <table class="donor-rpt-table donor-rpt-table-wide">
                                <thead>
                                    <tr>
                                        <th>كود المشروع</th>
                                        <th>اسم المشروع</th>
                                        <th>اسم المشروع لدى المانح</th>
                                        <th>نوع المشروع</th>
                                        <th>الحالة</th>
                                        <th>تاريخ الاعتماد</th>
                                        <th>تاريخ البداية</th>
                                        <th>تاريخ النهاية</th>
                                        <th>التكلفة المخططة</th>
                                        <th>المستلم</th>
                                        <th>الفائض/العجز</th>
                                        <th>صافي الصرف</th>
                                        <th>التنفيذ المدفوع</th>
                                        <th>المتبقي من الصرف</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($report['projects'] as $project)
                                        <tr>
                                            <td dir="ltr">{{ $project['code'] ?: '-' }}</td>
                                            <td>{{ $project['name'] }}</td>
                                            <td>{{ $project['donor_project_name'] ?: '-' }}</td>
                                            <td>{{ $project['super_name'] ?: '-' }}</td>
                                            <td>{{ $project['status_name'] ?: '-' }}</td>
                                            <td dir="ltr">{{ $project['approval_date'] ?: '-' }}</td>
                                            <td dir="ltr">{{ $project['start_date'] ?: '-' }}</td>
                                            <td dir="ltr">{{ $project['end_date'] ?: '-' }}</td>
                                            @foreach (['planned' => false, 'received' => false, 'surplus' => true, 'final' => false, 'execution_paid' => false, 'remaining' => true] as $key => $signed)
                                                <td>
                                                    @forelse ($project[$key] as $code => $value)
                                                        <span class="{{ $signed ? $signClass($value) : 'donor-rpt-number' }}">
                                                            {!! $money($value) !!} <span class="donor-rpt-number-code">{{ $code }}</span>
                                                        </span>
                                                    @empty
                                                        <span class="donor-rpt-number" style="color: var(--dr-muted);">—</span>
                                                    @endforelse
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                {{-- E) Project cost details --}}
                <section class="donor-rpt-card">
                    <div class="donor-rpt-section-title">تفاصيل تكاليف المشاريع</div>
                    @if (empty($report['cost_details']))
                        <div class="donor-rpt-empty">لا توجد بنود تكاليف</div>
                    @else
                        <div class="donor-rpt-table-wrap">
                            <table class="donor-rpt-table">
                                <thead>
                                    <tr>
                                        <th>المشروع</th>
                                        <th>نوع الحساب / بند التكلفة</th>
                                        <th>مبلغ التكلفة</th>
                                        <th>العملة</th>
                                        <th>المبلغ المستلم</th>
                                        <th>الفائض/العجز</th>
                                        <th>ملاحظات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($report['cost_details'] as $row)
                                        <tr>
                                            <td>
                                                <span dir="ltr">{{ $row['project_code'] ?: '' }}</span>
                                                {{ $row['project_name'] }}
                                            </td>
                                            <td>{{ $row['account_type'] ?: '-' }}</td>
                                            <td><span class="donor-rpt-number">{!! $money($row['amount']) !!}</span></td>
                                            <td><span class="donor-rpt-badge" dir="ltr">{{ $row['currency_code'] ?: '-' }}</span></td>
                                            <td><span class="donor-rpt-number">{!! $money($row['received']) !!}</span></td>
                                            <td><span class="{{ $signClass($row['surplus']) }}">{!! $money($row['surplus']) !!}</span></td>
                                            <td>{{ $row['notes'] ?: '-' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                {{-- F) Financial movements --}}
                <section class="donor-rpt-card">
                    <div class="donor-rpt-section-title">الحركات المالية ({{ count($report['movements']) }})</div>
                    @if (empty($report['movements']))
                        <div class="donor-rpt-empty">لا توجد حركات مالية</div>
                    @else
                        <div class="donor-rpt-table-wrap">
                            <table class="donor-rpt-table donor-rpt-table-wide">
                                <thead>
                                    <tr>
                                        <th>التاريخ</th>
                                        <th>نوع الحركة</th>
                                        <th>المشروع</th>
                                        <th>رقم المعاملة</th>
                                        <th>المرجع</th>
                                        <th>المبلغ</th>
                                        <th>العملة</th>
                                        <th>المبلغ النهائي (بعد الخصومات والتحويل)</th>
                                        <th>عملة الصرف</th>
                                        <th>ملاحظات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($report['movements'] as $row)
                                        <tr>
                                            <td dir="ltr">{{ $row['date'] ? \Illuminate\Support\Carbon::parse($row['date'])->format('Y-m-d') : '-' }}</td>
                                            <td>{{ $row['kind_label'] }}</td>
                                            <td>
                                                <span dir="ltr">{{ $row['project_code'] ?: '' }}</span>
                                                {{ $row['project_name'] }}
                                            </td>
                                            <td dir="ltr">{{ $row['transaction_number'] ?: '-' }}</td>
                                            <td>{{ $row['reference'] ?: '-' }}</td>
                                            <td><span class="donor-rpt-number">{!! $money($row['amount']) !!}</span></td>
                                            <td><span class="donor-rpt-badge" dir="ltr">{{ $row['currency_code'] ?: '-' }}</span></td>
                                            <td>
                                                @if ($row['final_amount'] !== null)
                                                    <span class="donor-rpt-number">{!! $money($row['final_amount']) !!}</span>
                                                @else
                                                    <span class="donor-rpt-number" style="color: var(--dr-muted);">-</span>
                                                @endif
                                            </td>
                                            <td><span class="donor-rpt-badge" dir="ltr">{{ $row['final_currency_code'] ?: '-' }}</span></td>
                                            <td>{{ $row['notes'] ?: '-' }}</td>
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
