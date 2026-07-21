@php
    /** @var \App\Models\Attachment|null $attachment */
    $storage = app(\App\Services\Attachments\AttachmentStorageService::class);
    $fileExists = $attachment ? $storage->exists($attachment) : false;
    $isImage = $attachment && str_starts_with((string) $storage->mimeType($attachment), 'image/');
    $viewUrl = $attachment ? route('attachments.show', [$attachment->id, 'view']) : null;
    $downloadUrl = $attachment ? route('attachments.show', [$attachment->id, 'download']) : null;
@endphp

<div dir="rtl" class="fi-secure-attachment-preview">
    @if (! $attachment)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            لا يوجد مرفق حالي
        </p>
    @elseif (! $fileExists)
        <p class="text-sm text-danger-600 dark:text-danger-400">
            تعذر العثور على ملف المرفق. قد يكون الملف مفقودًا.
        </p>
    @else
        <div class="flex flex-col gap-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            @if ($isImage)
                <img
                    src="{{ $viewUrl }}"
                    alt="{{ e($attachment->file_name) }}"
                    class="max-h-80 w-auto max-w-full rounded-md object-contain"
                />
            @else
                <div class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                    <svg class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                    <span class="break-all">{{ $attachment->file_name }}</span>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                @if ($attachment->file_type)
                    <span>{{ $attachment->file_type }}</span>
                @endif
                @if ($attachment->file_size)
                    <span>{{ number_format($attachment->file_size / 1024, 1) }} KB</span>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-3 text-sm">
                <a
                    href="{{ $viewUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400"
                >
                    عرض بالحجم الكامل
                </a>
                <a
                    href="{{ $downloadUrl }}"
                    class="inline-flex items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400"
                >
                    تنزيل
                </a>
            </div>
        </div>
    @endif
</div>
