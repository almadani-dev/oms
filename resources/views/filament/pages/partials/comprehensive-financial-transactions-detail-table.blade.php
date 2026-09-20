{{--
    The one and only rendering source for the "تفاصيل الحركات المالية" table.

    Rendered twice on the page — once for the full movement list, and once inside
    each expanded classification row for that classification's own lines. Both get
    identical columns, typography, sizing, RTL behaviour and horizontal navigation
    because there is a single markup source; only $rows differs.

    Expected variables (inherited from the page view unless overridden by @include):
      $rows               array    movement lines to render, already filtered
      $money              Closure  amount formatter
      $detailColumnCount  int      column count, for the notes-panel colspan
      $regionLabel        string   aria-label for the scrollable region

    Scroll-sync + notes-panel scope.

    openNotes holds the line_id of the movement row whose full notes panel is open.
    It lives here, on the shared ancestor, because the panel row is a SIBLING of its
    movement row: sibling rows cannot share an x-data scope, and giving every row its
    own tbody would break the nth-child zebra striping. Each rendering of this partial
    creates its own x-data scope, so every table keeps independent scroll and notes
    state — opening two classifications in turn never leaks one table's state to another.

    resize() keeps the ghost spacer exactly as wide as the table's scrollable content,
    so the top bar and the table share one identical scroll range and scrollLeft can be
    mirrored verbatim.

    mirror() copies the raw scrollLeft value. Both containers sit in the same dir="rtl"
    subtree and therefore share whatever sign convention the browser uses for scrollLeft
    in RTL — so nothing here assumes that value is positive. The lock flag, cleared on
    the next animation frame, stops the two scroll handlers from echoing each other.
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
        aria-label="{{ $regionLabel }}"
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
