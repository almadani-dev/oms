{{--
    The one and only rendering source for the "تفاصيل الحركات المالية" table.

    Rendered three times on the page — once for the full movement list, once
    inside an expanded classification row, and once inside an expanded
    transaction type row. All three get identical columns, typography, sizing,
    RTL behaviour and horizontal navigation because there is a single markup
    source; only $rows differs.

    Expected variables (inherited from the page view unless overridden by @include):
      $rows               array    movement lines to render, already filtered
      $money              Closure  amount formatter
      $detailColumnCount  int      column count, for the notes-panel colspan
      $regionLabel        string   aria-label for the scrollable region
      $blockKey           string   unique wire:key for this rendering (see below)

    ---------------------------------------------------------------------------
    Why every instance is self-contained
    ---------------------------------------------------------------------------

    Each rendering of this partial creates its OWN Alpine x-data scope. x-ref
    resolution is scoped to the owning x-data instance, so the repeated ref
    names below (topBar / bottomBar / bodyWrap / table) address only this
    instance's own elements. There is no global ref, no shared element id, no
    document.querySelector(), and no scroll state outside the component — which
    is what makes three detail tables on one page scroll, size and disable their
    arrows completely independently of each other.

    $blockKey is the other half of that guarantee. The classification and type
    detail blocks are inserted and removed by Livewire, and morphdom matches
    unkeyed siblings structurally: without a stable key it could match a newly
    inserted nested detail block against the main detail block, transplanting
    one table's Alpine instance (and therefore its refs, scroll position and
    arrow state) onto the other — which is exactly how a control bar appears to
    "move to" or "disappear from" the wrong section. A distinct wire:key per
    rendering pins each instance to its own section for good.

    ---------------------------------------------------------------------------
    Scroll controller
    ---------------------------------------------------------------------------

    refresh() keeps both ghost spacers exactly as wide as the table's scrollable
    content, so the two control bars and the table share one identical scroll
    range and scrollLeft can be mirrored verbatim between them.

    mirror() copies the raw scrollLeft value. All three containers sit in the
    same dir="rtl" subtree and therefore share whatever sign convention the
    browser uses for scrollLeft in RTL — so nothing here assumes that value is
    positive. The lock flag, cleared on the next animation frame, stops the
    scroll handlers from echoing each other.

    measure() probes this element's real numeric scrollLeft bounds by pushing it
    past both extremes and reading back what the browser clamped to, then
    restoring the saved position. That works under every RTL convention
    (0..max, -max..0, reversed) without hardcoding any of them, and it is what
    the arrow direction and the disabled-at-boundary state are derived from.

    Why refresh() watches the VIEWPORT as well as the content: the usable scroll
    range is scrollWidth MINUS clientWidth, so a change to either one moves the
    bounds. Re-probing on a content change alone leaves lo/hi permanently stale
    the moment the viewport changes on its own — and for these tables it always
    does, because the table's scrollWidth is pinned by its own content while the
    wrapper's clientWidth is not. That is why both are compared here.

    Why each .cft-scroll-bar carries wire:ignore: the ghost spacer's width is
    applied by this controller at runtime and does not exist in the server-
    rendered HTML. Livewire's morph syncs a surviving element's attributes back
    to what the server sent, so without wire:ignore EVERY Livewire round trip
    strips the spacer's inline width. The spacer then collapses to the track's
    own width, scrollWidth equals clientWidth, and the native thumb vanishes —
    leaving an empty track and two arrows. That is measured behaviour on the
    real page, not a theory: expanding a classification took the main table's
    spacer from 1926px to no style attribute at all, and its topBar from
    scrollWidth 1926 to 1030 against clientWidth 1030.

    It is specifically the SURVIVING main table that breaks. wire:key preserves
    its node, so Alpine never re-initialises it and settle() never re-runs; and
    the ResizeObserver below sees no change, because the wrapper and the table
    genuinely did not resize. Nothing was left to re-apply the width. A freshly
    inserted classification/type block is unaffected — it mounts and measures
    for the first time. wire:ignore is safe here because a control bar is inert
    presentational markup: a track and an empty spacer, with nothing inside it
    that the server ever re-renders. The spacers are also observed below, so an
    instance still repairs its own bar should anything else ever reset it.

    Why settle() measures across two frames: a classification/type block is
    inserted by Livewire, and its width is pinned a moment later by the
    .cft-nested-detail component that wraps it — a SIBLING Alpine instance in
    the same init pass. Reading geometry on the first tick can therefore catch
    the block mid-layout, with scrollWidth still <= clientWidth, which would
    leave the control bar with a track but no usable thumb and both arrows
    disabled. $nextTick lets Alpine finish the tree, and the two animation
    frames let the browser actually lay the table out and settle its fonts.

    openNotes holds the line_id of the movement row whose full notes panel is
    open. It lives on this same shared ancestor because the panel row is a
    SIBLING of its movement row: sibling rows cannot share an x-data scope, and
    giving every row its own tbody would break the nth-child zebra striping.
--}}
<div
    wire:key="{{ $blockKey }}"
    x-data="{
        lock: false,
        observer: null,
        openNotes: null,
        rtl: false,
        lo: 0,
        hi: 0,
        lastContent: -1,
        lastViewport: -1,
        frame: null,
        atStart: true,
        atEnd: true,

        init() {
            const body = this.$refs.bodyWrap;
            const bars = [this.$refs.topBar, this.$refs.bottomBar];

            this.rtl = getComputedStyle(body).direction === 'rtl';

            const mirror = (from) => {
                if (this.lock) return;
                this.lock = true;
                [body, ...bars].forEach((el) => {
                    if (el !== from) el.scrollLeft = from.scrollLeft;
                });
                this.edges();
                requestAnimationFrame(() => { this.lock = false; });
            };

            body.addEventListener('scroll', () => mirror(body), { passive: true });
            bars.forEach((bar) => bar.addEventListener('scroll', () => mirror(bar), { passive: true }));

            // Observes only this instance's own elements, so a resize of one
            // detail table never recalculates another's bars. It keeps the
            // spacers correct for the rest of this instance's life; settle()
            // only handles the very first measurement.
            this.observer = new ResizeObserver(() => this.refresh());
            this.observer.observe(body);
            this.observer.observe(this.$refs.table);

            // The spacers are watched too, so this instance re-applies its own
            // width if anything ever resets one. Re-applying an unchanged width
            // is a no-op for the observer, so this cannot loop.
            bars.forEach((bar) => this.observer.observe(bar.firstElementChild));

            this.settle();
        },

        /**
         * First measurement of a block Livewire may have inserted this very
         * tick. Measuring once immediately keeps the bar from flashing at the
         * wrong size; the two frames after it are what guarantee the numbers
         * are read from the final layout. Each call is idempotent — refresh()
         * only re-probes when the geometry it depends on actually moved.
         */
        settle() {
            this.$nextTick(() => {
                this.refresh();

                this.frame = requestAnimationFrame(() => {
                    this.refresh();
                    this.frame = requestAnimationFrame(() => {
                        this.frame = null;
                        this.refresh();
                    });
                });
            });
        },

        /**
         * Sizes this instance's own two ghost spacers from the real rendered
         * width, and re-derives its own scroll bounds whenever the content or
         * the viewport moved. Touches nothing outside this component.
         */
        refresh() {
            const body = this.$refs.bodyWrap;
            const bars = [this.$refs.topBar, this.$refs.bottomBar];

            // The table's own scrollWidth is consulted too, so a frame in which
            // the wrapper has already been resized but the table has not been
            // re-laid out yet still yields the true content width.
            const content = Math.max(body.scrollWidth, this.$refs.table.scrollWidth);
            const viewport = body.clientWidth;

            bars.forEach((bar) => { bar.firstElementChild.style.width = content + 'px'; });

            // Re-probing mid-animation would cancel a smooth scroll, so it only
            // happens when something the bounds actually depend on changed.
            if (content !== this.lastContent || viewport !== this.lastViewport) {
                this.lastContent = content;
                this.lastViewport = viewport;
                this.measure();
            }

            bars.forEach((bar) => { bar.scrollLeft = body.scrollLeft; });
            this.edges();
        },

        /**
         * Real numeric scrollLeft bounds of THIS container, read from the
         * browser itself rather than assumed from the RTL convention.
         */
        measure() {
            const body = this.$refs.bodyWrap;
            const saved = body.scrollLeft;

            body.scrollLeft = -1000000;
            this.lo = body.scrollLeft;
            body.scrollLeft = 1000000;
            this.hi = body.scrollLeft;
            body.scrollLeft = saved;
        },

        /**
         * Boundary state for this table's own arrows only. In RTL the
         * beginning of the table sits at the numeric maximum of scrollLeft and
         * the end at the minimum; in LTR it is the other way round.
         */
        edges() {
            const pos = this.$refs.bodyWrap.scrollLeft;

            if (this.hi - this.lo <= 1) {
                this.atStart = true;
                this.atEnd = true;
                return;
            }

            const startEdge = this.rtl ? this.hi : this.lo;
            const endEdge = this.rtl ? this.lo : this.hi;

            this.atStart = Math.abs(pos - startEdge) <= 1;
            this.atEnd = Math.abs(pos - endEdge) <= 1;
        },

        /**
         * towardStart === true moves toward the beginning of the table,
         * false toward its end — a logical direction, resolved from the
         * container's computed direction rather than from the sign of any
         * scrollLeft value.
         */
        nudge(towardStart) {
            const body = this.$refs.bodyWrap;
            const step = Math.max(160, Math.round(body.clientWidth * 0.8));
            const sign = (this.rtl ? 1 : -1) * (towardStart ? 1 : -1);

            body.scrollBy({ left: sign * step, behavior: 'smooth' });
        },

        destroy() {
            if (this.observer) this.observer.disconnect();
            if (this.frame) cancelAnimationFrame(this.frame);
        },
    }"
>
    {{-- Top control bar: [toward start] [scrollbar] [toward end].
         The bar duplicates the table's native scrollbar and is hidden from
         assistive tech so the table is announced as one scrollable region. --}}
    <div class="cft-scroll-controls">
        <button
            type="button"
            class="cft-scroll-arrow"
            @click="nudge(true)"
            :disabled="atStart"
            aria-label="تمرير الجدول نحو البداية"
        >
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 0-1.06L10.94 10 7.21 6.29a.75.75 0 1 1 1.06-1.06l4.25 4.24a.75.75 0 0 1 0 1.06l-4.25 4.24a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd" />
            </svg>
        </button>

        <div class="cft-scroll-bar" x-ref="topBar" aria-hidden="true" wire:ignore>
            <div class="cft-scroll-ghost"></div>
        </div>

        <button
            type="button"
            class="cft-scroll-arrow"
            @click="nudge(false)"
            :disabled="atEnd"
            aria-label="تمرير الجدول نحو النهاية"
        >
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1 0 1.06L9.06 10l3.73 3.71a.75.75 0 1 1-1.06 1.06l-4.25-4.24a.75.75 0 0 1 0-1.06l4.25-4.24a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
        </button>
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

    {{-- Bottom control bar — identical markup and behaviour to the top one,
         sitting directly below this table's own native scrollbar. It belongs
         to this instance alone and is removed with it. --}}
    <div class="cft-scroll-controls cft-scroll-controls-bottom">
        <button
            type="button"
            class="cft-scroll-arrow"
            @click="nudge(true)"
            :disabled="atStart"
            aria-label="تمرير الجدول نحو البداية"
        >
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 0-1.06L10.94 10 7.21 6.29a.75.75 0 1 1 1.06-1.06l4.25 4.24a.75.75 0 0 1 0 1.06l-4.25 4.24a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd" />
            </svg>
        </button>

        <div class="cft-scroll-bar" x-ref="bottomBar" aria-hidden="true" wire:ignore>
            <div class="cft-scroll-ghost"></div>
        </div>

        <button
            type="button"
            class="cft-scroll-arrow"
            @click="nudge(false)"
            :disabled="atEnd"
            aria-label="تمرير الجدول نحو النهاية"
        >
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1 0 1.06L9.06 10l3.73 3.71a.75.75 0 1 1-1.06 1.06l-4.25-4.24a.75.75 0 0 1 0-1.06l4.25-4.24a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" />
            </svg>
        </button>
    </div>
</div>
