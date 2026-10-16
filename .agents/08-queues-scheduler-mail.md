# Queues, scheduler, mail

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

## PART 14 — Queues and jobs

A job is a class with `handle()`:

```php
class SendReceipt extends Widdx\Job {
    public function handle(array $data): void { … }
}
```

Push it:

```
queue(SendReceipt::class, ['id' => 7]);
```

- Default driver `sync` runs the job immediately (fine for dev and shared hosting).
- Set `QUEUE_DRIVER=database` in `.env` for real background processing.
- Jobs retry: `$tries = 3` default, wait = `$backoff * 2^(attempt-1)` seconds.
- After all tries fail the job moves to `failed_jobs`.

Cron-friendly commands (no daemon needed):

```
php widdx queue:work --max=50
```

- `queue:work` STOPS when the queue is empty. This is by design (shared-hosting safe).
- `php widdx queue:failed` lists dead jobs; `php widdx queue:retry all` revives them.

---

## PART 15 — Scheduler (one crontab line total)

Declare tasks in `routes/console.php`:

```php
Widdx\Schedule::command('queue:work --max=50')->everyMinute();
```

Available frequencies: `everyMinute everyFiveMinutes everyTenMinutes everyFifteenMinutes everyThirtyMinutes hourly daily monthly` and `interval(seconds)`. Add a condition with `->when(fn() => …)`. Run due tasks:

```
php widdx schedule:run
```

cPanel/crontab entry — the ONLY one you need:

```
* * * * * php /full/path/widdx schedule:run
```

State is remembered in `storage/app/schedule.json`, so repeated cron runs are safe.

---

## PART 16 — Mail

```
mail('to@site.com', 'Subject', 'welcome-template', ['name' => 'Ali']);
```

- Default driver `log` writes the mail to storage/logs (perfect for dev/tests).
- Set `MAIL_DRIVER=smtp` + `MAIL_HOST/PORT/USERNAME/PASSWORD/ENCRYPTION` for real delivery.
- The SMTP client is hand-written (sockets) — no external library, works on shared hosting.
- `mail()->attach('/path/file.pdf')->raw($to, $subject, $body);`
- Header injection into subjects/emails throws a MailException. Good.

---
