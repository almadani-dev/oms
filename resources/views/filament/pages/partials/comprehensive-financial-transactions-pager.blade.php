{{--
    Pager for one "تفاصيل الحركات المالية" table (main, classification or type).

    Expected variables:
      $pager       array{page, last, from, to, total}|null   from the page's getViewData()
      $method      string   Livewire method that moves THIS table's page
      $pagerKey    string   unique wire:key for this pager
      $pagerLabel  string   aria-label for the navigation landmark

    Rendered only when the table has more than one page, so a report that fits
    on one page looks exactly as it did before pagination existed.

    Every button calls $method with an absolute page number and is targeted by
    method name alone, so the spinner and the disabled state belong to this
    one table: paging the main table never spins a classification's pager,
    and vice versa. Nothing here rebuilds the report — the next render reads
    that one page: in live mode by a keyset walk of the report projection
    (only First / Previous / Next / Last exist, which is all this pager
    offers), in fallback mode from the immutable report snapshot.
--}}
@if ($pager !== null && $pager['last'] > 1)
    <nav class="cft-pager" wire:key="{{ $pagerKey }}" aria-label="{{ $pagerLabel }}">
        <button
            type="button"
            class="cft-pager-button"
            wire:click="{{ $method }}(1)"
            wire:target="{{ $method }}"
            wire:loading.attr="disabled"
            @disabled($pager['page'] <= 1)
        >
            الأولى
        </button>

        <button
            type="button"
            class="cft-pager-button"
            wire:click="{{ $method }}({{ $pager['page'] - 1 }})"
            wire:target="{{ $method }}"
            wire:loading.attr="disabled"
            @disabled($pager['page'] <= 1)
        >
            السابقة
        </button>

        <span class="cft-pager-status">
            صفحة {{ $pager['page'] }} من {{ $pager['last'] }} — البنود {{ $pager['from'] }}–{{ $pager['to'] }} من {{ $pager['total'] }}
        </span>

        <span
            class="cft-spinner"
            role="status"
            aria-label="جارٍ تحميل الصفحة"
            wire:loading.inline-flex
            wire:target="{{ $method }}"
        ></span>

        <button
            type="button"
            class="cft-pager-button"
            wire:click="{{ $method }}({{ $pager['page'] + 1 }})"
            wire:target="{{ $method }}"
            wire:loading.attr="disabled"
            @disabled($pager['page'] >= $pager['last'])
        >
            التالية
        </button>

        <button
            type="button"
            class="cft-pager-button"
            wire:click="{{ $method }}({{ $pager['last'] }})"
            wire:target="{{ $method }}"
            wire:loading.attr="disabled"
            @disabled($pager['page'] >= $pager['last'])
        >
            الأخيرة
        </button>
    </nav>
@endif
