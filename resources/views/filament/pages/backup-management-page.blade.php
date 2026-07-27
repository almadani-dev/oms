<x-filament-panels::page>
    @php
        $restoreTampered = $this->restoreTamperedState();
        $staleRestore = $restoreTampered ? null : $this->staleRestoreViewData();
        $activeRestore = $this->activeRestoreViewData();
    @endphp

    @if ($restoreTampered)
        <div class="fi-section rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-800 dark:border-danger-800 dark:bg-danger-950 dark:text-danger-200">
            تم اكتشاف حالة استعادة غير موثوقة. لا يمكن بدء استعادة جديدة أو تأكيد العملية من الواجهة. يلزم فحص يدوي وفق دليل الاستعادة.
        </div>
    @endif

    @if ($staleRestore)
        <div class="fi-section rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-200 space-y-1">
            <p class="font-semibold">تم اكتشاف عملية استعادة سابقة متوقفة أو لم يتم تحديثها منذ فترة. يجب مراجعة حالتها قبل بدء عملية استعادة جديدة.</p>
            <p>معرّف الاستعادة: {{ $staleRestore['uuid8'] }}</p>
            <p>النسخة المصدر: {{ $staleRestore['source_uuid8'] ?? '—' }}</p>
            <p>نسخة الأمان: {{ $staleRestore['safety_uuid8'] ?? '—' }}</p>
            <p>آخر مرحلة معروفة: {{ $staleRestore['last_phase_label'] }}</p>
            <p>عمر آخر نبضة: {{ $staleRestore['heartbeat_age_minutes'] !== null ? $staleRestore['heartbeat_age_minutes'].' دقيقة' : '—' }}</p>
            <p>حالة المرفقات: {{ $staleRestore['attachment_state_label'] ?? '—' }}</p>
            <p>وضع الصيانة: {{ $staleRestore['maintenance_active'] ? 'مفعل' : 'غير مفعل' }}</p>
        </div>
    @endif

    @if ($activeRestore)
        <div
            x-data="{
                phaseLabels: @js($activeRestore['phase_labels']),
                phase: null,
                lastHeartbeat: null,
                errorSummary: null,
                terminal: false,
                timer: null,
                async poll() {
                    try {
                        const response = await fetch(@js($activeRestore['poll_url']));

                        if (! response.ok) {
                            return;
                        }

                        const json = await response.json();

                        this.phase = json.phase ?? this.phase;
                        this.lastHeartbeat = json.last_heartbeat_at ?? this.lastHeartbeat;
                        this.errorSummary = json.error_summary ?? null;

                        if (json.result) {
                            this.terminal = true;

                            if (this.timer) {
                                clearInterval(this.timer);
                            }

                            setTimeout(() => window.location.reload(), 3000);
                        }
                    } catch (e) {
                        // Transient network/maintenance-mode hiccups are
                        // expected — the next tick simply tries again.
                    }
                },
                phaseLabel() {
                    if (! this.phase) {
                        return @js($activeRestore['initial_phase_label']);
                    }

                    return this.phaseLabels[this.phase] ?? this.phase;
                },
                init() {
                    this.poll();
                    this.timer = setInterval(() => this.poll(), 7000);
                },
            }"
            class="fi-section rounded-xl border border-info-200 bg-info-50 p-4 text-sm text-info-800 dark:border-info-800 dark:bg-info-950 dark:text-info-200 space-y-1"
        >
            <p class="font-semibold">عملية استعادة جارية</p>
            <p>النسخة المصدر: {{ $activeRestore['source_uuid8'] }}</p>
            <p>النطاق: {{ $activeRestore['scope_label'] }}</p>
            <p>طلبها: {{ $activeRestore['requested_by'] }}</p>
            <p>وقت البدء: {{ $activeRestore['started_at'] }}</p>
            <p>المرحلة الحالية: <span x-text="phaseLabel()"></span></p>
            <p>آخر نبضة: <span x-text="lastHeartbeat ?? '—'"></span></p>
            <p x-show="errorSummary" x-text="errorSummary"></p>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
