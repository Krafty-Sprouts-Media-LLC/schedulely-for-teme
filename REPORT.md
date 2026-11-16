# Report: Schedulely ported to TemeCMS

`krafty-sprouts/schedulely` 0.1.0, built from the WordPress plugin Schedulely 1.8.15 (read only) using only Teme's handbook, generated references and `teme/contracts`. Author: Krafty Sprouts Media, LLC. Developed against `C:\Users\kings\Downloads\Projects\teme-plugins\teme-dev` (Teme `main` at 0.8.0-alpha.1 plus two documentation commits).

Nothing dangerous or irreversible came up, so the run never had to stop. Nothing outside the new folder was deleted; the WordPress plugin and teme-dev's code were not changed; no real key, password or site was touched.

## Verdict in one paragraph

The scheduling engine, both orderings, capacity maths, settings, storage, automation and status notices are built and tested, and the plugin enables cleanly on the dev site (manifest accepted, doors accepted, four tables created, `teme:doctor` reports 0 errors). **What has not been seen is a real scheduled item:** Teme gives a plugin author no way to put test content on a development site (FINDINGS H9), so the path from a saved draft to `ContentWriter::schedule()` has been tested against fakes of Teme's contracts only. The biggest limits come from sockets Teme does not have yet: no way to list or read content (so the queue is learned from hooks and misses drafts that existed before the plugin was enabled), no author field, no email or notice door, and no custom admin screen.

## Tests

| | Result |
|---|---|
| Pest, all suites | **228 passed, 0 failed, 1083 assertions** |
| `Unit` (pure logic: time, window, capacity, slots, scheduler, ordering, AI ordering, options) | 158 tests, 904 assertions |
| `Storage` (SQLite through the real migration: stores, hook tracker, run service, automation, status notices) | 70 tests, 179 assertions |
| Pint (Teme's rules, minus two that fight the file header) | passes |
| PHPStan level 6 | no errors |
| `bin/smoke.ps1` on the dev site | passes: enable, migrate, four tables, `teme:doctor` with 0 errors |

Every feature was written test first (the test file was run and seen to fail on the missing classes before the code was written; commit messages say so). Run them:

```powershell
cd C:\Users\kings\Downloads\Projects\teme-plugins\schedulely
php ..\teme-dev\vendor\bin\pest --configuration=phpunit.xml
```

No test touches a network or a real key (a `FakeHttp` and the string `dummy-key-not-real`), and none touches the site's database.

## What works

Same as WordPress (details in FEATURES.md):

- **Scheduling modes**: Random (trial and error with the attempt budget, minimum interval honoured), Sequential (equal slots) and Hybrid (random second inside each slot), with the safety buffer, the quota, active weekdays, same-day and overnight windows, site timezone and daylight saving, deficit completion (fill the furthest day first) and the 21-day lookahead.
- **Failure handling**: a failed Random write is retried twice with new times; a failed slot write counts toward the day; a stopped job leaves what it scheduled.
- **Capacity maths** with all three suggestions (lower interval, lower quota, widen window).
- **Queue ordering**: shuffle; PHP ordering (series stem with the US state removed, `even` or `round_robin`); US timezone-aware ordering (state detection, waking-hours overlap, distribution); AI ordering through `OutboundHttp` with JSON mode, response reconciliation, fallback to PHP and the rolling reorder log. Reordering never runs on an automatic run.
- **Automation**: auto-schedule on/off with a 1, 6, 12 or 24 hour interval, an hourly schedule, a "Run now" toggle, and two jobs with progress and cancellation.
- **Settings**: 27 declared settings in one group with the key as a secret, a Tools link, defaults that a test keeps equal to the code's.

## What is different, and what cannot be done yet

Each is a wall in FINDINGS.md (W1 to W12) with what was expected and what was done.

| Wall | Effect |
|---|---|
| W1, W12: no read or list socket for content | The queue and the ledger of scheduled times are tables filled by hooks. **Drafts that existed before the plugin was enabled, imported drafts and items scheduled earlier are invisible** until saved again. This is the most important limit. |
| W2: no author on writes or reads, no list of accounts | Random authors became a rotation of the acting person among account IDs typed in; preserved authors are not possible. |
| W3: no mail, and `Notices` not offered to plugins (proved) | No notification email, no AI summary. Notices on every admin page instead. |
| W4: no custom admin screen or button | No stat cards, no capacity pill, no reorder-log view, no Clear button; "Run now" is a toggle. |
| W5, W6: no dynamic setting options, outbound hosts fixed | Content types and accounts are text lists; AI provider is one of three declared hosts. |
| W7, W11: no AI contract, 10 second call limit | AI ordering is capped at 150 items per request; bigger queues use PHP ordering. |
| W8: no plugin-defined hooks, no way to extend the API or agent tools | The five WordPress Abilities exist only as tested classes. |
| W9, W10: a scheduled job has no acting person; schedules are fixed in the manifest | `acting_account` setting; an hourly schedule with the interval decided in code. |

## A second attempt at live content (after the first report)

With the owner's go-ahead I made a throwaway editor account (`schedulely-dev@example.invalid`, user ID 3; the set-password link was withheld and never used) and a temporary seeder plugin that creates six drafts through `ContentWriter::create`, to push them through Schedulely for real. It could not be started: plugin schedules never came due (FINDINGS H17) and `Jobs::queue()` refuses without a signed-in person or the operator (H16). Nothing was created. The seeder, its `.env` path, its marker file and the temporary manifest edits were removed, and the shipped manifest is unchanged. **The account still exists on teme-dev; delete it in the admin if you do not want it.** A stale "schedulely-seeder" row also shows as Disabled in `teme:plugins` because the console has no command that forgets a plugin whose folder is gone. The one code change that came out of it: the "Run now" trigger now ignores a refused `queue()` instead of throwing (test added). The only way left is to sign in to the admin, save a few drafts and use "Run now" (steps below).

## Not verified on a live site

- The path from a real draft to a real scheduled item: `ContentSaved` to the queue, `ContentWriter::schedule()`, `ContentScheduled` back to the ledger, and the item publishing at its time (H9, H13).
- That a `multiselect` setting returns what `Options` expects (H12), that status notices show as intended, where job progress shows, and that a saved "Run now" starts a job. All need a signed-in session or content.
- The hourly schedule firing: the site's scheduler role is not running (`teme:doctor` says so).

## How to try it on teme-dev

The plugin is already registered in the site's `.env` (`TEME_PLUGIN_PATHS` ends with `C:/Users/kings/Downloads/Projects/teme-plugins/schedulely`) and enabled; the other two plugins were left alone.

1. Check it: `powershell -File bin\smoke.ps1`. (Or by hand: `php artisan teme:plugins`, then `php artisan teme:plugins:enable krafty-sprouts/schedulely --agree`.)
2. Sign in to the admin. Under **Settings › Plugin settings › Schedulely** set the window, quota and mode. Set **Account to act as** to the ID of a person (needed for any run; there is no way for a plugin to look one up). Keep **Schedule automatically** off for a first try.
3. Save some drafts of the `post` type (open and save each; drafts that already existed are not seen until saved again).
4. Turn **Run now** on and save. A job named "Schedule the queued items" starts (Tools › Jobs; a queue worker must be running: `php artisan queue:work`). Then look at the Posts list for scheduled items.
5. To run again, turn **Run now** off, save, then on and save. To try AI ordering, turn on **Reorder the queue**, choose **AI ordering**, paste a key for the chosen service into **AI service key**, and run it by hand.
6. For automatic runs, turn on **Schedule automatically** and start the scheduler (`php artisan schedule:work`); `php artisan teme:plugins:run-due-schedules` queues the hourly job by hand.
7. To remove it: `php artisan teme:plugins:disable krafty-sprouts/schedulely`, then `php artisan teme:plugins:remove-data krafty-sprouts/schedulely`.

## Deviations from the brief, and why

- `composer install` on teme-dev hung with no output for eight minutes and was stopped; the pull had only added planning documents (no lockfile or source change), so nothing needed installing or building. `migrate` said nothing to migrate (DECISIONS D2).
- `requires.teme` is `^0.8 || ^0.9` because the dev site is 0.8.0-alpha.1 while the contracts used are `@since 0.9.0` (FINDINGS H2).
- Teme's own application code was not read (D1, D23), which is why live content could not be made (H9).

## Files

`FEATURES.md` (what is the same, different or not possible), `FINDINGS.md` (12 walls, W1 to W12, and 15 handbook gaps, H1 to H15), `DECISIONS.md` (D1 to D24), `AGENTS.md`, `REPORT.md`, `teme-plugin.json`, `src/`, `database/migrations/`, `tests/`, `bin/smoke.ps1`, `pint.json`, `phpstan.neon`.
