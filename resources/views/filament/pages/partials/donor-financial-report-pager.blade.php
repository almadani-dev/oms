{{--
    Pager of one "تقرير الجهات المانحة" table (movements, projects or cost items).

    Expected variables:
      $pager       array{page, last, from, to, total}   from the page's getViewData()
      $method      string   Livewire method that moves THIS table's page (absolute page number)
      $noun        string   what the rows are, for the status line ("الحركات", "المشاريع", "البنود")
      $pagerLabel  string   aria-label of the navigation landmark

    Rendered only when the table has more than one page. Each table has its
    own method, so its buttons, spinner and disabled state belong to it alone;
    the server clamps the page.
--}}
@if ($pager['last'] > 1)
    <nav class="donor-rpt-pager" wire:key="donor-rpt-pager-{{ $method }}" aria-label="{{ $pagerLabel }}">
        <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-double-right" wire:click="{{ $method }}(1)" wire:target="{{ $method }}" wire:loading.attr="disabled" :disabled="$pager['page'] <= 1">الأولى</x-filament::button>
        <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-right" wire:click="{{ $method }}({{ $pager['page'] - 1 }})" wire:target="{{ $method }}" wire:loading.attr="disabled" :disabled="$pager['page'] <= 1">السابقة</x-filament::button>
        <span class="donor-rpt-pager-info">
            <span wire:loading.remove wire:target="{{ $method }}">صفحة {{ number_format($pager['page']) }} من {{ number_format($pager['last']) }} — {{ $noun }} {{ number_format($pager['from']) }}–{{ number_format($pager['to']) }} من {{ number_format($pager['total']) }}</span>
            <span wire:loading wire:target="{{ $method }}">جاري التحميل…</span>
        </span>
        <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-left" icon-position="after" wire:click="{{ $method }}({{ $pager['page'] + 1 }})" wire:target="{{ $method }}" wire:loading.attr="disabled" :disabled="$pager['page'] >= $pager['last']">التالية</x-filament::button>
        <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-double-left" icon-position="after" wire:click="{{ $method }}({{ $pager['last'] }})" wire:target="{{ $method }}" wire:loading.attr="disabled" :disabled="$pager['page'] >= $pager['last']">الأخيرة</x-filament::button>
    </nav>
@endif
