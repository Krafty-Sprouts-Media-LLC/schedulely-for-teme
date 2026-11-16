# Schedulely for Teme

Spreads queued drafts across future publishing windows. Items in the chosen status (draft or in review) are given publish times inside a daily window, up to a daily quota, with a minimum gap, on chosen weekdays, in Random, Sequential or Hybrid mode, optionally ordered so a series is spaced apart (in PHP, or by an AI service with PHP as the fallback). A Teme port of the WordPress plugin of the same name; `FEATURES.md` says what is the same, different, or not yet possible.

## Capabilities and hooks (`teme-plugin.json`)

- Capabilities: `content.read`, `content.write`, `jobs`, `http` (hosts `api.deepseek.com`, `api.openai.com`, `openrouter.ai`, only when AI ordering is on).
- Hooks: `ContentSaved`, `ContentScheduled`, `ContentPublished`, `ContentUnpublished`, `ContentTrashed`, `ContentRestored`, `ContentDeleted` (they keep the plugin's queue and ledger tables), and `SettingsChanged` (the "Run now" toggle).
- Jobs: `run` (a person's run, with progress) and `auto-run` (queued hourly by the schedule; gates itself on the "Schedule automatically" setting and its interval).
- Tables (`owns`): `schedulely_queue`, `schedulely_slots`, `schedulely_state`, `schedulely_reorder_log`, from `database/migrations`.
- Settings: one group, `plugin.schedulely`, declared under `contributes.settings`; the key `ai_api_key` is a secret. Settings are read when a run starts, never in a constructor.

## Layout

- `src/Time`, `src/Engine`, `src/Ordering`: pure logic with no Teme or Laravel in it (window, capacity, slot planner, scheduler, PHP and AI ordering, US timezones).
- `src/Storage`: the four stores and `QueueTracker` (hooks to tables). `src/Run`: `RunService`, `AutoRunner`, the two jobs, `RunNowTrigger`. `src/Status`: the notices raised on every admin page. `src/Settings`: `Defaults` and `Options`.
- `src/SchedulelyPlugin.php` is the only class Teme builds.

Rules for this package: `declare(strict_types=1)`; a file header (filename, author, description, `@package`, `@since`) on every PHP file and `@since` on every class and public method; every sentence a person reads goes through `Support\Words::say()`; no class from Teme's `App` namespace, ever; no real API key in a file or a test (tests use `FakeHttp` and a dummy key).

## Run the tests

From this folder, with the development site's dependencies (Pest, Illuminate, `teme/contracts`) next door at `../teme-dev` (or set `SCHEDULELY_TEME_DEV`):

```powershell
php ..\teme-dev\vendor\bin\pest --configuration=phpunit.xml
php ..\teme-dev\vendor\bin\pint --config=pint.json --test src tests database
php ..\teme-dev\vendor\bin\phpstan analyse -c phpstan.neon --no-progress
```

The tests never touch the site's database: stores run against in-memory SQLite through the real migration file. `bin/smoke.ps1` checks the plugin against a real development site.

## Handbook

[Your first plugin](../teme-dev/docs/guides/developers/start/first-plugin.md), [Hooks](../teme-dev/docs/guides/developers/concepts/hooks.md), and [Rules for plugins and themes](../teme-dev/docs/guides/developers/rules/plugins-and-themes.md). Where the handbook was silent or wrong, `FINDINGS.md` says so.
