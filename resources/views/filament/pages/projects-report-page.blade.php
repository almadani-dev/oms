<x-filament-panels::page>
    {{ $this->table }}

    @php($totals = $this->getCurrencyTotals())

    @if (filled($totals))
        <div class="mt-6">
            <h2 class="text-lg font-bold mb-3">الإجماليات حسب العملة</h2>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($totals as $block)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <div class="px-5 py-3 border-b border-gray-100 dark:border-white/10">
                            <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">العملة</span>
                            <span class="ms-1 text-base font-bold text-primary-600 dark:text-primary-400">
                                {{ $block['currency_label'] }}
                            </span>
                        </div>

                        <dl class="divide-y divide-gray-100 dark:divide-white/10 px-5">
                            <div class="flex items-center justify-between py-2">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">إجمالي التكلفة</dt>
                                <dd class="text-sm font-semibold tabular-nums">{!! \App\Helpers\NumberHelper::bigComma($block['sum_cost']) !!}</dd>
                            </div>
                            <div class="flex items-center justify-between py-2">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">إجمالي المستلم</dt>
                                <dd class="text-sm font-semibold tabular-nums">{!! \App\Helpers\NumberHelper::bigComma($block['sum_received']) !!}</dd>
                            </div>
                            <div class="flex items-center justify-between py-2">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">إجمالي المنفّذ</dt>
                                <dd class="text-sm font-semibold tabular-nums">{!! \App\Helpers\NumberHelper::bigComma($block['sum_executed']) !!}</dd>
                            </div>
                            <div class="flex items-center justify-between py-2">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">إجمالي متبقي الاستلام</dt>
                                <dd class="text-sm font-semibold tabular-nums">{!! \App\Helpers\NumberHelper::bigComma($block['sum_remaining']) !!}</dd>
                            </div>
                            <div class="flex items-center justify-between py-2">
                                <dt class="text-sm text-gray-500 dark:text-gray-400">إجمالي متاح للتنفيذ</dt>
                                <dd class="text-sm font-semibold tabular-nums">{!! \App\Helpers\NumberHelper::bigComma($block['sum_available']) !!}</dd>
                            </div>
                        </dl>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-filament-panels::page>
