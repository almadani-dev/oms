# Local OMS runtime — process isolation of both sites (2026-10-07)

LOCAL DEVELOPMENT MACHINE ONLY. Nothing here applies to production.

## Why

Apache on this machine (2.4.62 Apache Lounge, WinNT MPM, threaded) used to run
every Laravel request — `oms.test` (`.env`, database `oms`) **and** the LAN
stress site (`.env.stress`, database `oms_stress`) — inside ONE `mod_php`
(PHP 8.3.28 TS) process.

Laravel reads the environment on every request (no config cache), and
`putenv()` / `getenv()` are process-wide. Concurrent threads therefore saw
missing or foreign values. Measured: 600 parallel probes on the old setup gave
the results below.

| | Stress | Normal |
|---|---|---|
| Missing `APP_KEY` | 6 | 6 |
| sqlite driver | 13 | 7 |
| `APP_ENV=production` | — | 10 |
| wrong database | — | 6 |

An empty `APP_KEY` changes Livewire 4's endpoint hash
(`/livewire-0e06790f` → `/livewire-4aeeedbb`), so valid Livewire POSTs got
**404**. `MissingAppKeyException` produced **500s**.

## Architecture now

Since the second step on 2026-10-07, neither site runs Laravel inside Apache's
threaded `mod_php` any more. Each site has its own pool of FastCGI
`php-cgi.exe` workers (mod_fcgid). A worker handles one request at a time,
and its environment is fixed when it starts.

| Site | URL | PHP runtime | Environment |
|---|---|---|---|
| Normal dev | `http://oms.test/admin` | FastCGI pool A (`05-oms-test-fcgid.conf`) | nothing injected → `.env` → `local`, `oms`, `storage\` |
| Stress (synthetic) | `http://172.16.0.100/oms/public/admin` | FastCGI pool B (`10-oms-stress-lan-fcgid.conf`) | `APP_ENV=stress`, `DB_DATABASE=oms_stress`, `LARAVEL_STORAGE_PATH=…\storage\stress` (worker process env + per-request params) |
| Any other host name | e.g. `http://localhost/oms/public` | `mod_php` (default vhost, unchanged) | `.env` → normal `oms`; not used for OMS work |

**Config files** in `C:\laragon\etc\apache2\sites-enabled\`:

- `05-oms-test-fcgid.conf`:
  - `ServerName oms.test` / `ServerAlias *.oms.test`.
  - It loads before Laragon's regenerated `auto.oms.test.conf`, and Apache
    uses the first matching name-based vhost. The auto file stays a harmless,
    shadowed duplicate; `httpd -S` lists it second.
  - Its wrapper command line (`php-cgi.exe -d variables_order=GPCS`) differs
    from the stress one, so the pools can never share a process class.
- `10-oms-stress-lan-fcgid.conf`: matches `Host: 172.16.0.100` only.
- Both files:
  - load `mod_fcgid-2.3.10-win64-VS16.so`;
  - use `SetHandler fcgid-script` inside `oms\public`;
  - run up to 16 workers per pool, recycled after 1,000 requests.

**Disabled:** `C:\laragon\etc\apache2\alias\oms-stress-dev.conf.off`, the old
global `<Location "/oms/public"> SetEnv …` inside `mod_php`.

**Backups:**

- Step 1 (stress), in `C:\laragon\etc\apache2\_backup_2026-10-07_stress-isolation\`:
  - `alias__oms-stress-dev.conf`
  - `sites-enabled__00-default.conf`
  - `sites-enabled__auto.oms.test.conf`
  - `mod_php.conf`
  - `fcgid.conf`
  - `httpd.conf`
- Step 2 (oms.test), in `C:\laragon\etc\apache2\_backup_2026-10-07_oms-test-isolation\`:
  - `sites-enabled__auto.oms.test.conf`
  - `sites-enabled__00-default.conf`
  - `sites-enabled__10-oms-stress-lan-fcgid.conf`
  - `mod_php.conf`
  - `laragon.ini`

No application file and no business data was changed for the runtime switch.

## After restarting the laptop

- **Starts automatically:**
  1. Start Laragon as usual.
  2. Apache starts.
  3. Both worker pools are spawned by Apache on each site's first request.

  No PHP worker command is needed for browsing.
- **Open:**
  - normal: `http://oms.test/admin`;
  - stress: `http://172.16.0.100/oms/public/admin`.
- **Confirm stress:** the red banner «STRESS TEST ENVIRONMENT, SYNTHETIC DATA
  ONLY – بيئة اختبار الضغط (oms_stress)» is on every page. The normal site never
  shows it.
- **Use the exact host names.** Another host name for `/oms/public` reaches
  normal `oms` through the old `mod_php` default vhost.
- **Restarting Apache:** use Laragon **Apache → Stop**, then **Start**. A plain
  "Reload" was observed NOT to restart the Apache child.
- **Queue worker:** still only needed for large background Excel exports:
  `php artisan --env=stress queue:work database --queue=reports --timeout=1900`
  (stress), or without `--env=stress` for normal.

## Returning the LAN path to normal `oms`

1. Rename `sites-enabled\10-oms-stress-lan-fcgid.conf` to `…conf.off`.
2. Laragon: **Apache → Stop**, then **Start**.

`http://172.16.0.100/oms/public/admin` is then normal `oms` (default vhost).

## Rollback

- **`oms.test` back to `mod_php`:** delete `sites-enabled\05-oms-test-fcgid.conf`,
  then Stop and Start Apache. Laragon's own `auto.oms.test.conf` serves it again.
- **Stress back to the original alias:** delete
  `sites-enabled\10-oms-stress-lan-fcgid.conf`, rename
  `alias\oms-stress-dev.conf.off` back to `oms-stress-dev.conf`, then Stop and
  Start Apache.

No database restore is needed; nothing in `oms` or `oms_stress` depends on the
runtime.

## Verified (2026-10-07, after both steps)

**Identity probes**, booting Laravel like `index.php`, all HTTP 200:

| Run | Requests | Concurrency |
|---|---|---|
| 1 | 4,000 | 64 |
| 2 | 8,000 | 96 |
| 3 | 8,000 | 64, with real traffic running at the same time |
| **Total** | **20,000** (10,000 per site) | |

- Missing key, wrong environment, database, driver, storage or Livewire hash:
  **0** for both sites.
- Cross-environment leaks: **0**.
- Worker PID overlap between the sites: **0**.

**Real application requests**, both sites concurrently:

- 3,600 requests: login pages, real Livewire update POSTs using each page's own
  snapshot and CSRF token, and guest `/admin` redirects. All 200 / 302.
- The Livewire endpoint was `0e06790f` on every page.
- The stress banner appeared on 600/600 stress pages and 0/600 normal pages.

**Logs after the test-script fix (14:16:28 onward):**

- 11,791 × 200 and 2,584 × 302.
- 0 × 404, 0 × 5xx, 0 non-200 Livewire responses.
- 0 Laravel errors on either site.

**Load-test artifacts, not runtime defects:**

- **Port exhaustion:** a runaway loop in my load script (13:59–14:16) opened
  tens of thousands of short TCP connections. That exhausted Windows'
  ephemeral ports and caused 224 `SQLSTATE[2002] Only one usage of each socket
  address` 500s.
- **View compilation race:** 7 Blade view-compile `rename()` failures happened
  at the first concurrent compile of changed views on the normal site.
  - Cause: Windows cannot atomically replace a compiled view that another
    process is reading.
  - Effect: transient; it occurs only on the very first compile after a view
    changes.
  - Scope: inherent to Laravel on Windows under parallel requests, with either
    runtime.
