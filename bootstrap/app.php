<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })
    ->withSchedule(function (Schedule $schedule): void {
        // No --force: safety-net incremental refresh, only dirty/missing snapshots.
        $schedule->command('reports:refresh-projects-financial')->hourly();

        // OMS Task 7B.1 — backup schedule. Always Asia/Gaza regardless of
        // config('app.timezone') (currently Asia/Jerusalem — a distinct
        // IANA zone from Asia/Gaza even though they share today's UTC
        // offset). Every entry only queues a job (App\Console\Commands\
        // CreateBackup/BackupRetentionCommand dispatch and return
        // immediately) — schedule:run itself never blocks on a real
        // mysqldump/archive/encrypt run. onOneServer() is safe here: the
        // default cache store in every environment this app runs in
        // (config('cache.default') = 'database' in production/local,
        // 'array' in testing — see config/cache.php / phpunit.xml) is an
        // Illuminate\Contracts\Cache\LockProvider, which is what
        // onOneServer() requires; a 'file' cache store would NOT support
        // it and this call would need to be reconsidered if that ever
        // changes.
        $schedule->command('oms:backup --type=daily --scope=full')
            ->timezone('Asia/Gaza')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('oms:backup --type=weekly --scope=full')
            ->timezone('Asia/Gaza')
            ->fridays()
            ->at('02:30')
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('oms:backup-retention')
            ->timezone('Asia/Gaza')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer();

        // OMS Task 7C.7 — detection only (see RestoreStaleDetector's own
        // docblock): never retries, resumes, relaunches, or mutates a
        // restore's status. Every minute is justified here precisely
        // because the command itself is read-only and its own notification
        // spam is already bounded by a Cache cooldown key.
        $schedule->command('oms:restore-watchdog')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();
    })->create();
