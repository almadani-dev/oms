<x-filament-panels::page>

    {{-- =========================================================
         COST SIDE — per (project × cost currency)
         ========================================================= --}}
    {{ $this->table }}

    @php($totals = $this->getCurrencyTotals())

    @if (filled($totals))
        <div class="mt-6">
            <h2 class="text-lg font-bold mb-3">الإجماليات حسب عملة التكلفة</h2>

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
                                <dt class="text-sm text-gray-500 dark:text-gray-400">إجمالي متبقي الاستلام</dt>
                                <dd class="text-sm font-semibold tabular-nums">{!! \App\Helpers\NumberHelper::bigComma($block['sum_remaining']) !!}</dd>
                            </div>
                        </dl>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- =========================================================
         EXECUTION SIDE — per (project × disbursement currency)
         A separate grain: every figure here is in the disbursement
         (execution) currency, never mixed with the cost currency.
         ========================================================= --}}
    @php($execRows = $this->getExecutionRows())
    @php($execTotals = $this->getExecutionTotals())

    <div class="mt-10">
        <h2 class="text-lg font-bold mb-3">التنفيذ حسب عملة الصرف</h2>

        @if (filled($execRows))
            <div class="fi-ta rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr class="text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3 font-semibold text-start">كود المشروع</th>
                            <th class="px-4 py-3 font-semibold text-start">اسم المشروع</th>
                            <th class="px-4 py-3 font-semibold text-start">عملة الصرف</th>
                            <th class="px-4 py-3 font-semibold text-end">المرصود</th>
                            <th class="px-4 py-3 font-semibold text-end">المنفّذ</th>
                            <th class="px-4 py-3 font-semibold text-end">متاح للتنفيذ</th>
                            <th class="px-4 py-3 font-semibold text-center">نسبة الإنجاز</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($execRows as $row)
                            <tr>
                                <td class="px-4 py-3">{{ $row['project_code'] }}</td>
                                <td class="px-4 py-3">{{ $row['project_name'] }}</td>
                                <td class="px-4 py-3">{{ $row['currency_label'] }}</td>
                                <td class="px-4 py-3 text-end tabular-nums">{!! \App\Helpers\NumberHelper::bigComma($row['total_disbursed']) !!}</td>
                                <td class="px-4 py-3 text-end tabular-nums">{!! \App\Helpers\NumberHelper::bigComma($row['total_executed']) !!}</td>
                                <td class="px-4 py-3 text-end tabular-nums">{!! \App\Helpers\NumberHelper::bigComma($row['available']) !!}</td>
                                <td class="px-4 py-3 text-center tabular-nums">{{ $row['completion'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">لا توجد عمليات صرف مطابقة.</p>
        @endif

        @if (filled($execTotals))
            <div class="mt-6">
                <h3 class="text-base font-bold mb-3">الإجماليات حسب عملة الصرف</h3>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($execTotals as $block)
                        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <div class="px-5 py-3 border-b border-gray-100 dark:border-white/10">
                                <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">عملة الصرف</span>
                                <span class="ms-1 text-base font-bold text-primary-600 dark:text-primary-400">
                                    {{ $block['currency_label'] }}
                                </span>
                            </div>

                            <dl class="divide-y divide-gray-100 dark:divide-white/10 px-5">
                                <div class="flex items-center justify-between py-2">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">إجمالي المرصود</dt>
                                    <dd class="text-sm font-semibold tabular-nums">{!! \App\Helpers\NumberHelper::bigComma($block['sum_disbursed']) !!}</dd>
                                </div>
                                <div class="flex items-center justify-between py-2">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">إجمالي المنفّذ</dt>
                                    <dd class="text-sm font-semibold tabular-nums">{!! \App\Helpers\NumberHelper::bigComma($block['sum_executed']) !!}</dd>
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
    </div>
</x-filament-panels::page>
