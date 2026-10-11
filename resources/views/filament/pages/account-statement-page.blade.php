@php
    $money = fn ($value) => \App\Helpers\NumberHelper::bigComma($value) ?? '0.00';
    $dateTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i') : '-';
    $balanceClass = function ($value) {
        $sign = bccomp((string) $value, '0', 2);

        if ($sign > 0) {
            return 'acct-stmt-number acct-stmt-number-success';
        }

        if ($sign < 0) {
            return 'acct-stmt-number acct-stmt-number-danger';
        }

        return 'acct-stmt-number';
    };
    $pageSize = \App\Services\Reports\AccountStatement\AccountStatementReport::PAGE_SIZE;
    $matched = (int) ($summary['matched_lines'] ?? 0);
    $fromRow = $matched === 0 ? 0 : ($pageNumber - 1) * $pageSize + 1;
    $toRow = min($fromRow + count($rows) - 1, $matched);
    $accountPages = $hasSubmitted ? $this->accountSummaryPageCount() : 1;
    $dimensionFiltered = filled($appliedFilters['transaction_super_type_ids'] ?? []) || filled($appliedFilters['transaction_type_ids'] ?? []) || filled($appliedFilters['project_ids'] ?? []);
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
            --as-chip-bg: #eef2ff;
            --as-chip-text: #3730a3;
            --as-chip-border: #c7d2fe;
            --as-warning-bg: #fef3c7;
            --as-warning-border: #fde68a;
            --as-warning-text: #b45309;
            --as-info-bg: #eff6ff;
            --as-info-border: #bfdbfe;
            --as-info-text: #1d4ed8;

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
            --as-chip-bg: rgba(99, 102, 241, 0.14);
            --as-chip-text: #c7d2fe;
            --as-chip-border: rgba(129, 140, 248, 0.30);
            --as-warning-bg: rgba(245, 158, 11, 0.12);
            --as-warning-border: rgba(251, 191, 36, 0.24);
            --as-warning-text: #fde68a;
            --as-info-bg: rgba(59, 130, 246, 0.10);
            --as-info-border: rgba(96, 165, 250, 0.25);
            --as-info-text: #bfdbfe;
        }

        .acct-stmt-stack { display: flex; flex-direction: column; gap: 1.25rem; }

        .acct-stmt-card {
            background: var(--as-card-bg);
            border: 1px solid var(--as-card-border);
            border-radius: 1rem;
            box-shadow: var(--as-card-shadow);
            padding: 1.25rem;
        }

        .acct-stmt-card-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.9rem;
            color: var(--as-heading);
            font-size: 0.98rem;
            font-weight: 800;
        }

        .acct-stmt-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--as-soft-border);
        }

        .acct-stmt-chips { display: flex; flex-wrap: wrap; gap: 0.45rem; }

        .acct-stmt-chip {
            display: inline-flex;
            gap: 0.35rem;
            align-items: baseline;
            border-radius: 999px;
            background: var(--as-chip-bg);
            color: var(--as-chip-text);
            border: 1px solid var(--as-chip-border);
            padding: 0.25rem 0.7rem;
            font-size: 0.78rem;
            font-weight: 650;
            max-width: 100%;
        }

        .acct-stmt-chip b { font-weight: 800; white-space: nowrap; }

        .acct-stmt-currency-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1rem;
        }

        .acct-stmt-currency-card {
            background: var(--as-soft-bg);
            border: 1px solid var(--as-soft-border);
            border-radius: 0.875rem;
            padding: 1rem;
        }

        .acct-stmt-currency-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }

        .acct-stmt-kv {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.6rem 1rem;
        }

        .acct-stmt-muted { color: var(--as-muted); font-size: 0.78rem; font-weight: 650; }

        .acct-stmt-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: var(--as-badge-bg);
            color: var(--as-badge-text);
            border: 1px solid var(--as-badge-border);
            padding: 0.22rem 0.6rem;
            font-size: 0.76rem;
            font-weight: 750;
            white-space: nowrap;
        }

        .acct-stmt-alert {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            border-radius: 0.875rem;
            padding: 0.8rem 1rem;
            font-size: 0.84rem;
            font-weight: 700;
            line-height: 1.7;
        }

        .acct-stmt-warning { background: var(--as-warning-bg); border: 1px solid var(--as-warning-border); color: var(--as-warning-text); }

        .acct-stmt-info { background: var(--as-info-bg); border: 1px solid var(--as-info-border); color: var(--as-info-text); }

        .acct-stmt-table-wrap {
            overflow-x: auto;
            border: 1px solid var(--as-table-border);
            border-radius: 0.875rem;
            -webkit-overflow-scrolling: touch;
        }

        .acct-stmt-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }

        .acct-stmt-table-main { min-width: 1500px; }

        .acct-stmt-table-accounts { min-width: 860px; }

        .acct-stmt-table th {
            background: var(--as-th-bg);
            color: var(--as-th-text);
            font-weight: 750;
            padding: 0.75rem 0.85rem;
            text-align: right;
            white-space: nowrap;
            position: sticky;
            top: 0;
        }

        .acct-stmt-table td {
            color: var(--as-td-text);
            padding: 0.7rem 0.85rem;
            border-top: 1px solid var(--as-table-border);
            vertical-align: top;
            line-height: 1.6;
        }

        .acct-stmt-table tbody tr:nth-child(even) td { background: var(--as-row-even); }

        .acct-stmt-table tbody tr:hover td { background: var(--as-row-hover); }

        .acct-stmt-number {
            direction: ltr;
            text-align: right;
            display: block;
            color: var(--as-number);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .acct-stmt-number-success { color: var(--as-success); font-weight: 800; }

        .acct-stmt-number-danger { color: var(--as-danger); font-weight: 800; }

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

        .acct-stmt-account { font-weight: 700; color: var(--as-heading); white-space: nowrap; }

        /* One line; the full value stays in the title tooltip. */
        .acct-stmt-ellipsis {
            display: block;
            max-width: 11rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .acct-stmt-pager {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .acct-stmt-pager-info {
            min-width: 11rem;
            text-align: center;
            color: var(--as-muted);
            font-size: 0.82rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .acct-stmt-result-info {
            color: var(--as-muted);
            font-size: 0.8rem;
            font-weight: 650;
            font-variant-numeric: tabular-nums;
        }

        .acct-stmt-loading { opacity: 0.55; transition: opacity 120ms ease; pointer-events: none; }

        @media (max-width: 640px) {
            .acct-stmt-card { padding: 0.9rem; }
            .acct-stmt-currency-grid { grid-template-columns: 1fr; }
            .acct-stmt-pager-info { min-width: 100%; order: -1; }
        }
    </style>

    <div dir="rtl" class="acct-stmt-page">
        <div class="acct-stmt-stack">
            @unless ($projectionReady)
                <div class="acct-stmt-alert acct-stmt-warning">{{ \App\Filament\Pages\AccountStatementPage::NOT_READY_MESSAGE }}</div>
            @endunless

            <section class="acct-stmt-card">
                {{ $this->form }}

                <div class="acct-stmt-actions">
                    <x-filament::button
                        type="button"
                        icon="heroicon-o-magnifying-glass"
                        wire:click="showReport"
                        wire:loading.attr="disabled"
                        wire:target="showReport"
                        :disabled="! $projectionReady"
                    >
                        <span wire:loading.remove wire:target="showReport">عرض</span>
                        <span wire:loading wire:target="showReport">جاري التحميل…</span>
                    </x-filament::button>

                    <span class="acct-stmt-muted">لا يتغير التقرير المعروض إلا بعد الضغط على «عرض».</span>
                </div>
            </section>

            {{--
                Background Excel / CSV: polls a small JSON status endpoint
                (owner-scoped), never the Livewire page. Ready → the signed
                download starts and the page forgets the export (renderless
                call); failed → an inline notice. Polling stops either way.
            --}}
            @foreach ([
                ['id' => $excelExportId, 'key' => 'as-excel-export-', 'url' => $this->excelExportStatusUrl(), 'forget' => 'forgetExcelExport', 'label' => 'Excel'],
                ['id' => $csvExportId, 'key' => 'as-csv-export-', 'url' => $this->csvExportStatusUrl(), 'forget' => 'forgetCsvExport', 'label' => 'CSV'],
            ] as $backgroundExport)
            @if ($backgroundExport['id'] !== null)
                <section
                    class="acct-stmt-card"
                    wire:key="{{ $backgroundExport['key'] }}{{ $backgroundExport['id'] }}"
                    x-data="{
                        url: @js($backgroundExport['url']),
                        state: 'pending',
                        visible: true,
                        timer: null,
                        init() { this.timer = setTimeout(() => this.check(), 1500) },
                        destroy() { clearTimeout(this.timer) },
                        async check() {
                            try {
                                const response = await fetch(this.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
                                if (response.status === 200) {
                                    const status = await response.json()
                                    this.state = status.state
                                    if (status.state === 'ready' && status.download_url) { window.location.href = status.download_url; return this.finish(false) }
                                    if (status.state === 'failed') { return this.finish(true) }
                                } else if ([401, 403, 404].includes(response.status)) {
                                    this.state = 'failed'
                                    return this.finish(true)
                                }
                            } catch (e) {}
                            this.timer = setTimeout(() => this.check(), 2000)
                        },
                        finish(keepVisible) { this.visible = keepVisible; $wire.{{ $backgroundExport['forget'] }}() },
                    }"
                    x-show="visible"
                >
                    <div class="acct-stmt-alert acct-stmt-info" x-show="state !== 'failed'">جاري تجهيز ملف {{ $backgroundExport['label'] }} في الخلفية؛ سيبدأ التنزيل تلقائياً عند اكتماله. يمكنك متابعة تصفح التقرير.</div>
                    <div class="acct-stmt-alert acct-stmt-warning" x-show="state === 'failed'" x-cloak>تعذر تجهيز ملف {{ $backgroundExport['label'] }}. يرجى المحاولة مرة أخرى.</div>
                </section>
            @endif
            @endforeach

            @if (! $hasSubmitted)
                <section class="acct-stmt-card">
                    <div class="acct-stmt-empty">يرجى اختيار الحساب (أو نوع الحساب / البنك / العملة) والفترة ثم الضغط على عرض</div>
                </section>
            @else
                <div wire:loading.class="acct-stmt-loading" wire:target="showReport" class="acct-stmt-stack">
                    <section class="acct-stmt-card">
                        <div class="acct-stmt-card-title">
                            <span>الفلاتر المطبقة</span>
                            <span class="acct-stmt-result-info">{{ number_format($matched) }} حركة · {{ number_format($summary['account_count'] ?? 0) }} حساب</span>
                        </div>
                        <div class="acct-stmt-chips">
                            <span class="acct-stmt-chip"><b>الفترة:</b> <span dir="ltr">{{ $appliedFilters['date_from'] }}</span> ← <span dir="ltr">{{ $appliedFilters['date_to'] }}</span></span>
                            @foreach ($appliedFilterLabels as $label => $value)
                                <span class="acct-stmt-chip"><b>{{ $label }}:</b> {{ $value }}</span>
                            @endforeach
                        </div>
                    </section>

                    @if (($summary['mixed_currency_accounts'] ?? 0) > 0)
                        <div class="acct-stmt-alert acct-stmt-warning">{{ \App\Services\Reports\AccountStatement\AccountStatementExportLayout::MIXED_CURRENCY_WARNING }} ({{ number_format($summary['mixed_currency_accounts']) }} حساب)</div>
                    @endif

                    @if ($dimensionFiltered)
                        <div class="acct-stmt-alert acct-stmt-info">{{ \App\Services\Reports\AccountStatement\AccountStatementExportLayout::DIMENSION_NOTE }}</div>
                    @endif

                    <section class="acct-stmt-card">
                        <div class="acct-stmt-card-title"><span>الملخص حسب العملة</span></div>

                        @if (empty($summary['currencies']))
                            <div class="acct-stmt-empty">لا توجد حسابات مطابقة للفلاتر المحددة</div>
                        @else
                            <div class="acct-stmt-currency-grid">
                                @foreach ($summary['currencies'] as $currency)
                                    <article class="acct-stmt-currency-card" wire:key="as-cur-{{ $currency['currency_id'] }}">
                                        <div class="acct-stmt-currency-head">
                                            <span class="acct-stmt-badge" dir="ltr">{{ $currency['currency_code'] ?: '—' }}</span>
                                            <span class="acct-stmt-muted">{{ number_format($currency['accounts']) }} حساب · {{ number_format($currency['lines']) }} حركة</span>
                                        </div>
                                        <div class="acct-stmt-kv">
                                            <div><div class="acct-stmt-muted">الرصيد الافتتاحي</div><div class="{{ $balanceClass($currency['opening']) }}">{!! $money($currency['opening']) !!}</div></div>
                                            <div><div class="acct-stmt-muted">الرصيد الختامي</div><div class="{{ $balanceClass($currency['closing']) }}">{!! $money($currency['closing']) !!}</div></div>
                                            <div><div class="acct-stmt-muted">إجمالي المدين</div><div class="acct-stmt-number">{!! $money($currency['debit']) !!}</div></div>
                                            <div><div class="acct-stmt-muted">إجمالي الدائن</div><div class="acct-stmt-number">{!! $money($currency['credit']) !!}</div></div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </section>

                    @if (! empty($accountSummaries))
                        <section class="acct-stmt-card">
                            <div class="acct-stmt-card-title">
                                <span>ملخص الحسابات</span>
                                <span class="acct-stmt-result-info">{{ number_format($summary['account_count']) }} حساب</span>
                            </div>
                            <div class="acct-stmt-table-wrap" wire:loading.class="acct-stmt-loading" wire:target="goToAccountSummaryPage">
                                <table class="acct-stmt-table acct-stmt-table-accounts">
                                    <thead>
                                        <tr>
                                            <th>الحساب</th>
                                            <th>العملة</th>
                                            <th>الرصيد الافتتاحي</th>
                                            <th>إجمالي المدين</th>
                                            <th>إجمالي الدائن</th>
                                            <th>الرصيد الختامي</th>
                                            <th>عدد الحركات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($accountSummaries as $account)
                                            <tr wire:key="as-acc-{{ $account['account_id'] }}">
                                                <td class="acct-stmt-account">
                                                    {{ $account['account_label'] }}
                                                    @if ($account['mixed_currencies'])
                                                        <span class="acct-stmt-badge" title="حركات بعملات مختلفة">⚠</span>
                                                    @endif
                                                </td>
                                                <td><span class="acct-stmt-badge" dir="ltr">{{ $account['currency_code'] ?: '—' }}</span></td>
                                                <td><span class="{{ $balanceClass($account['opening']) }}">{!! $money($account['opening']) !!}</span></td>
                                                <td><span class="acct-stmt-number">{!! $money($account['debit']) !!}</span></td>
                                                <td><span class="acct-stmt-number">{!! $money($account['credit']) !!}</span></td>
                                                <td><span class="{{ $balanceClass($account['closing']) }}">{!! $money($account['closing']) !!}</span></td>
                                                <td><span class="acct-stmt-number">{{ number_format($account['lines']) }}</span></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if ($accountPages > 1)
                                <nav class="acct-stmt-pager" aria-label="صفحات ملخص الحسابات">
                                    <x-filament::button size="sm" color="gray" wire:click="goToAccountSummaryPage(1)" :disabled="$accountSummaryPage <= 1">الأولى</x-filament::button>
                                    <x-filament::button size="sm" color="gray" wire:click="goToAccountSummaryPage({{ $accountSummaryPage - 1 }})" :disabled="$accountSummaryPage <= 1">السابقة</x-filament::button>
                                    <span class="acct-stmt-pager-info">صفحة {{ number_format($accountSummaryPage) }} من {{ number_format($accountPages) }}</span>
                                    <x-filament::button size="sm" color="gray" wire:click="goToAccountSummaryPage({{ $accountSummaryPage + 1 }})" :disabled="$accountSummaryPage >= $accountPages">التالية</x-filament::button>
                                    <x-filament::button size="sm" color="gray" wire:click="goToAccountSummaryPage({{ $accountPages }})" :disabled="$accountSummaryPage >= $accountPages">الأخيرة</x-filament::button>
                                </nav>
                            @endif
                        </section>
                    @endif

                    <section class="acct-stmt-card">
                        <div class="acct-stmt-card-title">
                            <span>الحركات</span>
                            @if ($matched > 0)
                                <span class="acct-stmt-result-info">عرض {{ number_format($fromRow) }}–{{ number_format($toRow) }} من {{ number_format($matched) }} حركة</span>
                            @endif
                        </div>

                        @if ($matched === 0)
                            <div class="acct-stmt-empty">لا توجد حركات ضمن الفترة والفلاتر المحددة</div>
                        @else
                            <div class="acct-stmt-table-wrap" wire:loading.class="acct-stmt-loading" wire:target="firstPage,previousPage,nextPage,lastPage">
                                <table class="acct-stmt-table acct-stmt-table-main">
                                    <thead>
                                        <tr>
                                            <th>التاريخ</th>
                                            <th>رقم الحركة</th>
                                            <th>الحساب</th>
                                            <th>نوع الحركة</th>
                                            <th>التصنيف</th>
                                            <th>المشروع</th>
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
                                            <tr wire:key="as-line-{{ $row['line_id'] }}">
                                                <td style="white-space: nowrap;">{{ $dateTime($row['date']) }}</td>
                                                <td dir="ltr" style="white-space: nowrap; text-align: right;">{{ $row['transaction_number'] ?: '-' }}</td>
                                                <td class="acct-stmt-account">{{ $row['account_label'] ?: '-' }}</td>
                                                <td><span class="acct-stmt-ellipsis" title="{{ $row['type_name'] ?: '-' }}">{{ $row['type_name'] ?: '-' }}</span></td>
                                                <td><span class="acct-stmt-ellipsis" title="{{ $row['classification_name'] ?: '-' }}">{{ $row['classification_name'] ?: '-' }}</span></td>
                                                <td style="min-width: 160px;"><div class="acct-stmt-clamp" title="{{ $row['project_label'] ?: '-' }}">{{ $row['project_label'] ?: '-' }}</div></td>
                                                <td style="min-width: 200px;">
                                                    <div class="acct-stmt-clamp" title="{{ $row['description'] ?: '—' }}">{{ $row['description'] ?: '—' }}</div>
                                                </td>
                                                <td><span class="acct-stmt-badge">{{ $row['line_role_label'] }}</span></td>
                                                <td style="min-width: 200px;">
                                                    <div class="acct-stmt-clamp" title="{{ $row['line_description'] }}">{{ $row['line_description'] }}</div>
                                                </td>
                                                <td>{{ $row['notes'] ?: '-' }}</td>
                                                <td>
                                                    @if (bccomp($row['debit'], '0', 2) > 0)
                                                        <span class="acct-stmt-number">{!! $money($row['debit']) !!}</span>
                                                    @else
                                                        <span class="acct-stmt-number" style="color: var(--as-muted);">-</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if (bccomp($row['credit'], '0', 2) > 0)
                                                        <span class="acct-stmt-number">{!! $money($row['credit']) !!}</span>
                                                    @else
                                                        <span class="acct-stmt-number" style="color: var(--as-muted);">-</span>
                                                    @endif
                                                </td>
                                                <td><span class="{{ $balanceClass($row['running_balance']) }}">{!! $money($row['running_balance']) !!}</span></td>
                                                <td><span class="acct-stmt-badge" dir="ltr">{{ $row['currency_code'] ?: '-' }}</span></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <nav class="acct-stmt-pager" aria-label="صفحات الحركات">
                                <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-double-right" wire:click="firstPage" wire:loading.attr="disabled" :disabled="$pageNumber <= 1">الأولى</x-filament::button>
                                <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-right" wire:click="previousPage" wire:loading.attr="disabled" :disabled="$pageNumber <= 1">السابقة</x-filament::button>
                                <span class="acct-stmt-pager-info">
                                    <span wire:loading.remove wire:target="firstPage,previousPage,nextPage,lastPage">صفحة {{ number_format($pageNumber) }} من {{ number_format(max(1, $pageCount)) }}</span>
                                    <span wire:loading wire:target="firstPage,previousPage,nextPage,lastPage">جاري التحميل…</span>
                                </span>
                                <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-left" icon-position="after" wire:click="nextPage" wire:loading.attr="disabled" :disabled="$pageNumber >= $pageCount">التالية</x-filament::button>
                                <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-double-left" icon-position="after" wire:click="lastPage" wire:loading.attr="disabled" :disabled="$pageNumber >= $pageCount">الأخيرة</x-filament::button>
                            </nav>
                        @endif
                    </section>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
