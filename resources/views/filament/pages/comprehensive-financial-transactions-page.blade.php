@php
    $money = fn ($value) => \App\Helpers\NumberHelper::bigComma($value) ?? '0.00';

    // Detail columns of the main "تفاصيل الحركات المالية" table. Kept in one
    // place so the colspan of the per-row notes panel can never drift.
    $detailColumnCount = 16;
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

            /* Nested/expanded detail area — deliberately quieter than the
               parent report table so it reads as secondary detail. */
            --cft-sub-bg: #fcfcfd;
            --cft-sub-border: #edeff2;
            --cft-sub-row-border: #f1f3f5;
            --cft-sub-th-bg: #f7f8fa;
            --cft-sub-th-text: #4b5563;

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

            --cft-sub-bg: rgba(17, 24, 39, 0.55);
            --cft-sub-border: rgba(255, 255, 255, 0.07);
            --cft-sub-row-border: rgba(255, 255, 255, 0.055);
            --cft-sub-th-bg: rgba(255, 255, 255, 0.035);
            --cft-sub-th-text: #cbd5e1;
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

        /* Compact variant used inside the nested detail table, where the
           currency pill must stay a quiet marker rather than dominate the
           row. Inherits every colour token from .cft-badge, so dark mode
           needs no extra rule. */
        .cft-badge-xs {
            padding: 0.08rem 0.34rem;
            font-size: 0.69rem;
            font-weight: 700;
            line-height: 1.35;
            border-radius: 0.3rem;
            white-space: nowrap;
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

        /* ---- expand / collapse (classification rows + per-row notes) ---- */

        .cft-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: transparent;
            border: 0;
            padding: 0;
            color: inherit;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            text-align: inherit;
        }

        .cft-toggle:hover {
            color: var(--cft-heading);
        }

        .cft-toggle:focus-visible {
            outline: 2px solid var(--cft-success);
            outline-offset: 2px;
            border-radius: 0.25rem;
        }

        .cft-toggle[disabled] {
            cursor: progress;
            opacity: 0.75;
        }

        /* Fixed-size slot shared by the chevron and the loading spinner, so
           swapping one for the other can never nudge the row height. */
        .cft-toggle-icon {
            width: 0.9rem;
            height: 0.9rem;
            flex: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .cft-spinner {
            width: 0.9rem;
            height: 0.9rem;
            flex: none;
            border-radius: 999px;
            border: 2px solid var(--cft-badge-border);
            border-top-color: var(--cft-muted);
            animation: cft-spin 0.6s linear infinite;
        }

        @keyframes cft-spin {
            to { transform: rotate(360deg); }
        }

        @media (prefers-reduced-motion: reduce) {
            .cft-spinner {
                animation-duration: 1.6s;
            }
        }

        /* Chevron points to the inline-start (right in RTL) when collapsed and
           rotates down when open, so the affordance reads naturally in RTL
           without hardcoding a direction. */
        .cft-chevron {
            width: 0.9rem;
            height: 0.9rem;
            flex: none;
            transition: transform 0.15s ease;
            transform: rotate(0deg);
        }

        .cft-toggle[aria-expanded="true"] .cft-chevron {
            transform: rotate(-90deg);
        }

        /* Outruns both `.cft-table tbody tr:nth-child(even) td` and the hover
           rule, so an expanded panel always reads as one calm block rather
           than picking up the zebra stripe of whatever row index it landed on. */
        .cft-table tbody tr.cft-detail-row td,
        .cft-table tbody tr.cft-detail-row:hover td,
        .cft-table tbody tr td.cft-detail-cell {
            background: var(--cft-soft-bg);
            padding: 0;
        }

        .cft-detail-inner {
            padding: 0.75rem;
        }

        .cft-detail-title {
            color: var(--cft-heading);
            font-size: 0.78rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }

        .cft-subtable-wrap {
            overflow-x: auto;
            border: 1px solid var(--cft-sub-border);
            border-radius: 0.5rem;
            background: var(--cft-sub-bg);
        }

        .cft-subtable {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 980px;
        }

        .cft-subtable th {
            background: var(--cft-sub-th-bg);
            color: var(--cft-sub-th-text);
            font-size: 12px;
            font-weight: 800;
            padding: 7px 9px;
            line-height: 1.4;
            text-align: right;
            white-space: nowrap;
            border-bottom: 1px solid var(--cft-sub-border);
        }

        .cft-subtable td {
            color: var(--cft-td-text);
            font-size: 12px;
            padding: 8px 9px;
            border-top: 1px solid var(--cft-sub-row-border);
            vertical-align: top;
            line-height: 1.45;
        }

        /* The nested table sits on its own quiet surface; the parent table's
           zebra + hover tints would fight it, so they are neutralised and
           replaced with one very light hover cue. */
        .cft-subtable tbody tr:nth-child(even) td {
            background: transparent;
        }

        .cft-subtable tbody tr:hover td {
            background: var(--cft-row-even);
        }

        .cft-subtable tbody tr:first-child td {
            border-top: 0;
        }

        /* Numbers inside the nested table: compact, aligned, tabular, and
           deliberately less bold than the parent report's figures. */
        .cft-subtable .cft-number {
            font-size: 12px;
            font-weight: 500;
            letter-spacing: -0.01em;
        }

        .cft-subtable .cft-nowrap {
            white-space: nowrap;
        }

        /* Account: compact, wraps only when it truly must. */
        .cft-sub-account {
            min-width: 108px;
            max-width: 190px;
            word-break: break-word;
        }

        /* Description: the one column that earns horizontal room. */
        .cft-sub-description {
            min-width: 220px;
            max-width: 340px;
            word-break: break-word;
        }

        .cft-sub-notes {
            min-width: 210px;
            max-width: 330px;
        }

        .cft-sub-muted {
            color: var(--cft-muted);
        }

        /* ---- notes ---- */

        .cft-notes-list {
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
        }

        .cft-note-label {
            color: var(--cft-muted);
            font-size: 0.74rem;
            font-weight: 800;
            margin-bottom: 0.15rem;
        }

        /* pre-wrap keeps the author's line breaks without ever rendering the
           note as HTML — the text itself stays Blade-escaped. */
        .cft-note-text {
            color: var(--cft-td-text);
            font-size: 0.8rem;
            white-space: pre-wrap;
            word-break: break-word;
            line-height: 1.65;
        }

        .cft-note-compact {
            color: var(--cft-td-text);
            font-size: 0.78rem;
            margin-top: 0.3rem;
        }

        /* Notes inside the nested detail table: label directly above its own
           text, tighter gaps between sources. Full text is still rendered —
           nothing here clamps or truncates. */
        .cft-subtable .cft-notes-list {
            gap: 0.35rem;
        }

        .cft-subtable .cft-note-label {
            font-size: 11px;
            font-weight: 650;
            line-height: 1.35;
            margin-bottom: 0.05rem;
        }

        .cft-subtable .cft-note-text {
            font-size: 12px;
            line-height: 1.45;
        }

        .cft-notes-panel {
            border-inline-start: 3px solid var(--cft-badge-border);
            padding-inline-start: 0.8rem;
        }

        /* ---- synchronized top scrollbar (detail table only) ---- */

        .cft-scroll-top {
            overflow-x: auto;
            overflow-y: hidden;
            /* Firefox/Chrome both keep a usable thumb at this height. */
            height: 14px;
            margin-bottom: 0.35rem;
            border: 1px solid var(--cft-table-border);
            border-radius: 999px;
            background: var(--cft-soft-bg);
        }

        .cft-scroll-top-ghost {
            height: 1px;
        }

        .cft-table-wrap:focus-visible {
            outline: 2px solid var(--cft-success);
            outline-offset: 2px;
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

                {{-- C) Statistics by transaction category (تصنيف المعاملة) — distinct transactions, line-based totals per currency.
                     Each classification expands to show only its own movement lines, filtered in PHP from the
                     already-loaded $rows: no query runs when a classification is opened, and only the single
                     open classification's rows are ever rendered into the DOM. --}}
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
                                    @php
                                        $isOpen = $openCategoryKey === $summary['key'];
                                    @endphp
                                    <tr>
                                        <td>
                                            {{-- wire:target carries the SAME argument as wire:click, so Livewire
                                                 matches this element's loading state against that one parameterised
                                                 call only: clicking one classification never spins the others.
                                                 The button is disabled for the duration of its own request, which
                                                 is what stops a double click from queueing a second toggle. --}}
                                            <button
                                                type="button"
                                                class="cft-toggle"
                                                wire:click="toggleCategory(@js($summary['key']))"
                                                wire:target="toggleCategory(@js($summary['key']))"
                                                wire:loading.attr="disabled"
                                                aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                                aria-label="{{ $isOpen ? 'إخفاء' : 'عرض' }} تفاصيل حركات التصنيف: {{ $summary['name'] }}"
                                            >
                                                <span class="cft-toggle-icon">
                                                    <svg
                                                        class="cft-chevron"
                                                        viewBox="0 0 20 20"
                                                        fill="currentColor"
                                                        aria-hidden="true"
                                                        wire:loading.remove
                                                        wire:target="toggleCategory(@js($summary['key']))"
                                                    >
                                                        <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1 0 1.06L9.06 10l3.73 3.71a.75.75 0 1 1-1.06 1.06l-4.25-4.24a.75.75 0 0 1 0-1.06l4.25-4.24a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
                                                    </svg>
                                                    <span
                                                        class="cft-spinner"
                                                        role="status"
                                                        aria-label="جارٍ تحميل التفاصيل"
                                                        wire:loading.inline-flex
                                                        wire:target="toggleCategory(@js($summary['key']))"
                                                    ></span>
                                                </span>
                                                <span>{{ $summary['name'] }}</span>
                                            </button>
                                        </td>
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

                                    @if ($isOpen)
                                        @php
                                            // Filtered by the bucket's stable lookup ID — the exact key the
                                            // service aggregates by — so the rows shown are precisely the
                                            // rows behind the totals on the row above, even when two
                                            // classifications happen to share a display name.
                                            $categoryRows = array_values(array_filter(
                                                $rows,
                                                fn ($row) => $row['category_key'] === $summary['key'],
                                            ));
                                        @endphp
                                        <tr class="cft-detail-row">
                                            <td class="cft-detail-cell" colspan="4">
                                                <div class="cft-detail-inner">
                                                    <div class="cft-detail-title">
                                                        تفاصيل حركات التصنيف: {{ $summary['name'] }} ({{ count($categoryRows) }} بند)
                                                    </div>
                                                    <div class="cft-subtable-wrap">
                                                        <table class="cft-subtable">
                                                            <thead>
                                                                <tr>
                                                                    <th>التاريخ</th>
                                                                    <th>رقم الحركة / المرجع</th>
                                                                    <th>نوع المعاملة</th>
                                                                    <th>الحساب</th>
                                                                    <th>نوع البنك</th>
                                                                    <th>العملة</th>
                                                                    <th>مدين</th>
                                                                    <th>دائن</th>
                                                                    <th>البيان / الوصف</th>
                                                                    <th>الملاحظات</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                @foreach ($categoryRows as $categoryRow)
                                                                    <tr>
                                                                        <td class="cft-nowrap" dir="ltr" style="text-align: right;">{{ $categoryRow['date'] }}</td>
                                                                        <td class="cft-nowrap">{{ $categoryRow['reference'] }}</td>
                                                                        <td class="cft-nowrap">{{ $categoryRow['type'] }}</td>
                                                                        <td class="cft-sub-account">{{ $categoryRow['account'] }}</td>
                                                                        <td class="cft-nowrap">{{ $categoryRow['bank_type'] }}</td>
                                                                        <td class="cft-nowrap"><span class="cft-badge cft-badge-xs" dir="ltr">{{ $categoryRow['currency'] }}</span></td>
                                                                        <td class="cft-nowrap">
                                                                            @if ($categoryRow['debit'] > 0)
                                                                                <span class="cft-number">{!! $money($categoryRow['debit']) !!}</span>
                                                                            @else
                                                                                <span class="cft-number cft-sub-muted">-</span>
                                                                            @endif
                                                                        </td>
                                                                        <td class="cft-nowrap">
                                                                            @if ($categoryRow['credit'] > 0)
                                                                                <span class="cft-number">{!! $money($categoryRow['credit']) !!}</span>
                                                                            @else
                                                                                <span class="cft-number cft-sub-muted">-</span>
                                                                            @endif
                                                                        </td>
                                                                        <td class="cft-sub-description">{{ $categoryRow['transaction_description'] }}</td>
                                                                        <td class="cft-sub-notes">
                                                                            @if (count($categoryRow['notes']) === 0)
                                                                                <span class="cft-sub-muted">—</span>
                                                                            @else
                                                                                <div class="cft-notes-list">
                                                                                    @foreach ($categoryRow['notes'] as $note)
                                                                                        <div>
                                                                                            <div class="cft-note-label">{{ $note['label'] }}</div>
                                                                                            <div class="cft-note-text">{{ $note['text'] }}</div>
                                                                                        </div>
                                                                                    @endforeach
                                                                                </div>
                                                                            @endif
                                                                        </td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
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

                {{-- Detail table: one row per transaction line.
                     Wrapped in a scroll-sync component that mirrors scrollLeft between a ghost
                     scrollbar above the table and the table's own native scrollbar below it. --}}
                <section class="cft-card">
                    <div class="cft-card-title">تفاصيل الحركات المالية</div>

                    {{--
                        Scroll-sync + notes-panel scope for the detail table.

                        openNotes holds the line_id of the movement row whose full notes
                        panel is open. It lives here, on the shared ancestor, because the
                        panel row is a SIBLING of its movement row: sibling rows cannot
                        share an x-data scope, and giving every row its own tbody would
                        break the nth-child zebra striping.

                        resize() keeps the ghost spacer exactly as wide as the table's
                        scrollable content, so the top bar and the table share one identical
                        scroll range and scrollLeft can be mirrored verbatim.

                        mirror() copies the raw scrollLeft value. Both containers sit in the
                        same dir="rtl" subtree and therefore share whatever sign convention
                        the browser uses for scrollLeft in RTL — so nothing here assumes that
                        value is positive. The lock flag, cleared on the next animation
                        frame, stops the two scroll handlers from echoing each other.
                    --}}
                    <div
                        x-data="{
                            lock: false,
                            observer: null,
                            openNotes: null,
                            init() {
                                const top = this.$refs.topBar;
                                const body = this.$refs.bodyWrap;
                                const ghost = this.$refs.ghost;

                                const resize = () => {
                                    ghost.style.width = body.scrollWidth + 'px';
                                    top.scrollLeft = body.scrollLeft;
                                };

                                const mirror = (from, to) => {
                                    if (this.lock) return;
                                    this.lock = true;
                                    to.scrollLeft = from.scrollLeft;
                                    requestAnimationFrame(() => { this.lock = false; });
                                };

                                top.addEventListener('scroll', () => mirror(top, body), { passive: true });
                                body.addEventListener('scroll', () => mirror(body, top), { passive: true });

                                this.observer = new ResizeObserver(resize);
                                this.observer.observe(body);
                                this.observer.observe(this.$refs.table);

                                this.$nextTick(resize);
                            },
                            destroy() {
                                if (this.observer) this.observer.disconnect();
                            },
                        }"
                    >
                        {{-- Duplicate of the native scrollbar below; hidden from assistive tech
                             so the table is announced as one scrollable region, not two. --}}
                        <div class="cft-scroll-top" x-ref="topBar" aria-hidden="true">
                            <div class="cft-scroll-top-ghost" x-ref="ghost"></div>
                        </div>

                        <div
                            class="cft-table-wrap"
                            x-ref="bodyWrap"
                            tabindex="0"
                            role="region"
                            aria-label="تفاصيل الحركات المالية — جدول قابل للتمرير أفقيًا"
                        >
                            <table class="cft-table cft-table-detail" x-ref="table">
                                <thead>
                                    <tr>
                                        <th>التاريخ</th>
                                        <th>رقم القيد / رقم المعاملة</th>
                                        <th>تصنيف المعاملة</th>
                                        <th>نوع المعاملة</th>
                                        <th>البيان</th>
                                        <th>دور سطر القيد</th>
                                        <th>وصف سطر القيد</th>
                                        <th>الحساب</th>
                                        <th>نوع البنك</th>
                                        <th>نوع الحساب</th>
                                        <th>المشروع</th>
                                        <th>العملة</th>
                                        <th>مدين</th>
                                        <th>دائن</th>
                                        <th>المستخدم</th>
                                        <th>الملاحظات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        @php
                                            $noteCount = count($row['notes']);
                                            $singleShortNote = $noteCount === 1 && mb_strlen($row['notes'][0]['text']) <= 80;
                                        @endphp
                                        {{-- Notes open/close is local Alpine state on the shared ancestor:
                                             instant, and with no Livewire round trip. --}}
                                        <tr>
                                            <td dir="ltr" style="text-align: right; white-space: nowrap;">{{ $row['date'] }}</td>
                                            <td>{{ $row['reference'] }}</td>
                                            <td>{{ $row['category'] }}</td>
                                            <td>{{ $row['type'] }}</td>
                                            <td style="min-width: 200px;">
                                                <div class="cft-clamp" title="{{ $row['transaction_description'] }}">{{ $row['transaction_description'] }}</div>
                                            </td>
                                            <td><span class="cft-badge">{{ $row['line_role_label'] }}</span></td>
                                            <td style="min-width: 200px;">
                                                <div class="cft-clamp" title="{{ $row['line_description'] }}">{{ $row['line_description'] }}</div>
                                            </td>
                                            <td>{{ $row['account'] }}</td>
                                            <td>{{ $row['bank_type'] }}</td>
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
                                            <td style="min-width: 190px;">
                                                @if ($noteCount === 0)
                                                    <span style="color: var(--cft-muted);">—</span>
                                                @else
                                                    <button
                                                        type="button"
                                                        class="cft-toggle"
                                                        @click="openNotes = (openNotes === {{ $row['line_id'] }} ? null : {{ $row['line_id'] }})"
                                                        :aria-expanded="openNotes === {{ $row['line_id'] }} ? 'true' : 'false'"
                                                        aria-label="عرض كل الملاحظات لهذا البند ({{ $noteCount }})"
                                                    >
                                                        <svg class="cft-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                            <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1 0 1.06L9.06 10l3.73 3.71a.75.75 0 1 1-1.06 1.06l-4.25-4.24a.75.75 0 0 1 0-1.06l4.25-4.24a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
                                                        </svg>
                                                        <span>{{ $noteCount }} ملاحظة</span>
                                                    </button>

                                                    @if ($singleShortNote)
                                                        <div class="cft-note-compact" x-show="openNotes !== {{ $row['line_id'] }}">
                                                            <span class="cft-note-label">{{ $row['notes'][0]['label'] }}</span>
                                                            {{ $row['notes'][0]['text'] }}
                                                        </div>
                                                    @endif
                                                @endif
                                            </td>
                                        </tr>

                                        @if ($noteCount > 0)
                                            {{-- Full notes panel: every source, fully labelled, never clamped
                                                 and never truncated. Text stays Blade-escaped. --}}
                                            <tr class="cft-detail-row" x-show="openNotes === {{ $row['line_id'] }}" style="display: none;">
                                                <td class="cft-detail-cell" colspan="{{ $detailColumnCount }}">
                                                    <div class="cft-detail-inner cft-notes-panel">
                                                        <div class="cft-notes-list">
                                                            @foreach ($row['notes'] as $note)
                                                                <div>
                                                                    <div class="cft-note-label">{{ $note['label'] }}</div>
                                                                    <div class="cft-note-text">{{ $note['text'] }}</div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
