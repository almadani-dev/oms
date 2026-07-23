<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Private attachment storage: never symlinked into public/, no 'url'
        // key, and 'serve' deliberately omitted (defaults to false) so
        // Laravel's built-in local-disk signed-URL route (storage.<disk>,
        // registered by FilesystemServiceProvider only when serve => true)
        // never gets auto-registered for this disk. All access must go
        // through the authenticated AttachmentController route instead.
        'attachments' => [
            'driver' => 'local',
            'root' => storage_path('app/private/attachments'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // Private backup archive storage: same shape as the 'attachments'
        // disk (never symlinked, 'serve' omitted so Laravel's built-in
        // signed-URL local-disk route never auto-registers for it). All
        // access must go through the authenticated BackupDownloadController
        // route instead — never a Storage::url() or a raw disk path.
        'backups' => [
            'driver' => 'local',
            'root' => storage_path('app/private/backups'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // OMS Task 7C — private, transient restore workspace: signed
        // progress files, the decrypt/extraction workspace, staged
        // attachments, and the pre-swap attachments quarantine. Same shape
        // as 'attachments'/'backups' (never symlinked, no built-in signed-
        // URL route auto-registered for it) and deliberately a separate
        // disk from both — retention/backup-listing code must never see
        // restore scratch state, and restore's plaintext material must
        // never share a root with either published archives or live
        // attachments. No code writes to this disk yet (Task 7C.1 only
        // declares it); nothing under it is committed to the repository.
        'restores' => [
            'driver' => 'local',
            'root' => storage_path('app/private/restores'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
