# Auto-sync every 5 minutes on Hostinger (shared hosting)

The dashboard no longer needs a manual **Sync**. A scheduled command
(`integrations:sync`) queues a fresh sync for every connected integration
**every 5 minutes**, and the dashboard header + Data Health show the last sync
time.

For that schedule to actually fire on shared hosting you need **one cron job**
that runs Laravel's scheduler once a minute. This guide sets that up on
Hostinger, plus the queue so the sync jobs get processed.

---

## What's already in the app

- `routes/console.php` schedules the sync:
  ```php
  Schedule::command('integrations:sync')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
  ```
- `php artisan integrations:sync` — queues one `SyncIntegrationJob` per
  connected integration (the same path as the manual button).
- Header shows **“Auto-syncs every 5 min · Last sync: 3m ago”** and a
  **⟳ Sync now** button; **Data Health** shows each integration's Last Sync.

You only need to (1) add the scheduler cron and (2) make sure the queue is
processed. Two supported setups below — **pick ONE**.

---

## Find your paths first

In hPanel → **Advanced → SSH Access** (or the File Manager) note:

- **PHP binary** — usually `/usr/bin/php`, or a versioned one like
  `/usr/bin/php8.3`. In hPanel → *Advanced → PHP Configuration* to see the
  version; use the matching CLI binary. If unsure, `/usr/bin/php8.3` is common.
- **Project path** — the absolute path to the Laravel root (the folder that
  contains `artisan`), e.g.
  `/home/u123456789/domains/yourdomain.com/public_html`.
  (If you deployed the app in a subfolder, point at that folder.)

Test over SSH (optional but recommended):

```bash
cd /home/u123456789/domains/yourdomain.com/public_html
/usr/bin/php8.3 artisan integrations:sync
```

You should see `Queued: GoHighLevel — …` lines.

---

## Setup A — recommended (database queue, one cron)

This runs the scheduler every minute; Laravel fires `integrations:sync` on its
5-minute cadence and also drains the queue.

### 1. Use the database queue

In your `.env`:

```env
QUEUE_CONNECTION=database
```

Create the jobs table (once), over SSH:

```bash
php artisan queue:table   # only if the migration doesn't exist yet
php artisan migrate --force
```

### 2. Also drain the queue from the scheduler

Add this next to the sync line in `routes/console.php` so queued jobs get
worked without a long-running daemon (shared hosting forbids daemons):

```php
Schedule::command('queue:work --stop-when-empty --max-time=290 --tries=1 --sleep=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
```

> `--stop-when-empty` + `--max-time=290` means the worker processes whatever is
> waiting and then exits (well under any shared-hosting process limit), so it's
> not a forbidden always-on daemon.

### 3. Add the cron job (hPanel → Advanced → Cron Jobs)

- **Schedule:** every minute — `* * * * *`
- **Command:**
  ```
  /usr/bin/php8.3 /home/u123456789/domains/yourdomain.com/public_html/artisan schedule:run >> /dev/null 2>&1
  ```

That's it. `schedule:run` fires each minute; Laravel runs `integrations:sync`
every 5 minutes and the queue drainer every minute.

> **If hPanel won't allow a 1-minute cron** (some plans have a minimum
> interval), set the cron to every 5 minutes (`*/5 * * * *`) and point it
> **directly** at the command instead of the scheduler — see Setup B.

---

## Setup B — no queue worker (simplest, runs sync inline)

If you don't want to manage a queue at all, run the sync **inline** on a
5-minute cron. `--sync` makes the command do the work in its own process
instead of queueing.

### 1. Cron job (hPanel → Advanced → Cron Jobs)

- **Schedule:** every 5 minutes — `*/5 * * * *`
- **Command:**
  ```
  /usr/bin/php8.3 /home/u123456789/domains/yourdomain.com/public_html/artisan integrations:sync --sync >> /home/u123456789/sync.log 2>&1
  ```

This calls the command directly (no `schedule:run` needed) and syncs every
connected integration in one run. The log file lets you see results/errors.

> Keep the manual **Sync now** button and Data Health syncs working too: those
> queue jobs. With `QUEUE_CONNECTION=sync` in `.env` they run inline (fine for
> occasional manual use); with `database` you'd want Setup A's drainer.

---

## Verify it's working

1. Wait 5–10 minutes, then open the dashboard — the header should read
   **“Last sync: a few minutes ago.”** It live-updates (polls every minute) and
   the widgets reload when a newer sync lands.
2. **Data Health** — each integration's **Last Sync** should advance.
3. Over SSH you can watch the schedule:
   ```bash
   php artisan schedule:list          # shows the 5-min integrations:sync entry
   php artisan integrations:sync --sync   # run once by hand to confirm it works
   ```
4. Setup A: check jobs are draining — `php artisan queue:work --once` should
   process a job with no error; a stuck queue means the drainer cron isn't
   running.

---

## Troubleshooting

| Symptom | Likely cause / fix |
|---------|--------------------|
| Header stays “Last sync: never” | Cron not running or wrong paths. Re-check the PHP binary and project path; look at the cron log. |
| Cron log: `Could not open input file: artisan` | Wrong project path — point at the folder that contains `artisan`. |
| Jobs pile up, data never updates (Setup A) | Queue not draining — confirm the `queue:work --stop-when-empty` schedule line and that `schedule:run` runs each minute; confirm `QUEUE_CONNECTION=database` + jobs table migrated. |
| “PHP CLI version mismatch” / weird errors | Use the CLI binary that matches your site's PHP version (e.g. `/usr/bin/php8.3`). |
| Syncs overlap / hit GHL rate limits | Already guarded: `withoutOverlapping()` on the schedule and `ShouldBeUnique` on the job prevent a second sync of the same integration while one is running. |
| Want a different interval | Change `everyFiveMinutes()` in `routes/console.php` (e.g. `everyTenMinutes()`), or the cron interval in Setup B. |

---

## Notes

- Dashboards read only **local** rows, so a sync never blocks page loads — the
  UI just shows fresher numbers after each run.
- The sync is idempotent: each run replaces a dataset's rows transactionally, so
  a missed or repeated run can't corrupt data.
- Loop statistics do **not** auto-add new values on sync; click a loop's **⟳**
  (refresh) to pick up newly-synced owners, or edit the loop.
