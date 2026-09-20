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

        /* Width is set by the Alpine component in the markup; this only keeps the
           block from spilling before that first measurement lands. */
        .cft-nested-detail {
            max-width: 100%;
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

        .cft-notes-panel {
            border-inline-start: 3px solid var(--cft-badge-border);
            padding-inline-start: 0.8rem;
        }

        /* ---- synchronized top scrollbar (every detail table) ---- */

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
                                                    {{-- Pins the nested detail table to the width the
                                                         classification table's own scroll container can
                                                         actually show, so it carries its own synchronized
                                                         top/bottom horizontal scrollbars instead of
                                                         stretching its parent table to 1250px. --}}
                                                    <div
                                                        class="cft-nested-detail"
                                                        x-data="{
                                                            observer: null,
                                                            init() {
                                                                const host = this.$el.closest('.cft-table-wrap');
                                                                if (! host) return;

                                                                // 24px = the .cft-detail-inner padding on both sides.
                                                                const fit = () => {
                                                                    const width = host.clientWidth - 24;
                                                                    this.$el.style.width = width > 0 ? width + 'px' : '';
                                                                };

                                                                this.observer = new ResizeObserver(fit);
                                                                this.observer.observe(host);
                                                                this.$nextTick(fit);
                                                            },
                                                            destroy() {
                                                                if (this.observer) this.observer.disconnect();
                                                            },
                                                        }"
                                                    >
                                                        @include('filament.pages.partials.comprehensive-financial-transactions-detail-table', [
                                                            'rows' => $categoryRows,
                                                            'regionLabel' => 'تفاصيل حركات التصنيف: ' . $summary['name'] . ' — جدول قابل للتمرير أفقيًا',
                                                        ])
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
                     Rendered from the shared detail-table partial — the very same partial
                     an expanded classification row renders — so the two tables cannot
                     drift apart in columns, sizing, typography or scroll behaviour. --}}
                <section class="cft-card">
                    <div class="cft-card-title">تفاصيل الحركات المالية</div>

                    @include('filament.pages.partials.comprehensive-financial-transactions-detail-table', [
                        'rows' => $rows,
                        'regionLabel' => 'تفاصيل الحركات المالية — جدول قابل للتمرير أفقيًا',
                    ])
                </section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
