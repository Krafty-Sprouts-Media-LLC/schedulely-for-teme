# Schedulely: feature inventory (WordPress 1.8.15 → TemeCMS)

Source: the WordPress plugin `schedulely` 1.8.15 (read only). Every feature is
marked:

- **same in Teme**: behaves the same way for the person using it.
- **different in Teme**: the goal is kept, the way it works changes (how is said).
- **can't yet**: Teme has no socket for it. The missing socket is named. Each one
  is also a "wall" in [FINDINGS.md](FINDINGS.md).

Classifications were made from the handbook, the generated references and
`teme/contracts` only. Where a later step proved one wrong, the entry says so.

> **Dated 16 November 2026.** Written against Teme `main` at 0.8.0-alpha.1. By 22
> November `main` is the 1.0.0 line, and its handbook documents sockets for most of
> the "can't yet" and "different" rows below (a content queue reader with scheduled
> times, people and credits, notices, settings buttons, an AI connection, `personId()`,
> schedule switches). See the table at the top of [FINDINGS.md](FINDINGS.md). The marks
> below are **not** updated until the port is redone against them and tested.

## 1. Scheduling engine

| # | WordPress feature | Mark | Notes |
|---|---|---|---|
| 1.1 | Take items from a configured status and give them future publish times | **different** | Teme has no "list items" door. The plugin keeps its own queue table, filled from `ContentSaved`, `ContentUnpublished`, `ContentRestored` and similar hooks, and writes the time with `ContentWriter::schedule()`. Items that were already drafts before the plugin was enabled, or that came in through import, are not seen until they are saved again (**wall**: no read/list socket; import runs send no hooks). |
| 1.2 | Random mode (random time in the window, trial-and-error against used times, min-interval respected) | **same** | Ported as is, including the attempt budget (200 + 50 per used time). |
| 1.3 | Sequential mode (the usable window split into `quota` equal slots, slot start) | **same** | |
| 1.4 | Hybrid mode (equal slots, a random second inside each) | **same** | |
| 1.5 | Daily quota (1–100) | **same** | Declared as a `number` setting with `min`/`max`; Teme enforces it. |
| 1.6 | Time window (start/end, `5:00 PM` style) | **same** | A `text` setting. The parser takes `5:00 PM` and `17:00`. |
| 1.7 | Overnight windows (end at or before start → ends next morning), logical "anchor day" | **same** | |
| 1.8 | Minimum interval (1–1440 min), enforced in Random mode | **same** | As in WordPress, Sequential/Hybrid ignore it and only the capacity check uses it. |
| 1.9 | Active weekdays | **same** | A `multiselect` setting (Sun–Sat). |
| 1.10 | Site timezone for every time | **same** | Read from `general.timezone` through `SettingsReader`. |
| 1.11 | Safety buffer: never schedule less than 30 min ahead (`schedulely_schedule_safety_buffer_seconds` filter) | **different** | A `number` setting (minutes, default 30) instead of a PHP filter. Teme has no plugin-defined filters. |
| 1.12 | Deficit completion: if the furthest window holds fewer than `quota` items and has not closed, fill it first | **same** | Counts come from the plugin's own slot table (its own schedules plus `ContentScheduled` heard from anyone else). |
| 1.13 | Next active day logic, skipping inactive weekdays, 21-day lookahead for the first window | **same** | |
| 1.14 | Pool size: at most N (1–10000) items per run, oldest first | **different** | Oldest by when Schedulely first saw the draft (Teme gives a hook no created date). |
| 1.15 | Post types to schedule | **different** | A `text` setting of content type keys (default `post`). Teme's types are site-defined and a manifest `select` has fixed options (**wall**: no dynamic options). |
| 1.16 | Post status to monitor (`draft`, `pending`, …) | **different** | A `select` of `draft` / `review`, Teme's two pre-schedule statuses. |
| 1.17 | Polylang: fetch across all languages | **same** | Each language version is its own item in Teme and is queued like any other. |
| 1.18 | Retry a failed write up to 2 times with different times (Random mode) | **same** | `WriteRefused` plays the part of `WP_Error`. |
| 1.19 | Batched "Schedule Now" runs with a transient cursor so each request is short | **different** | A tracked background job with `progress()` replaces the HTTP batches. Batch size is still a setting. |
| 1.20 | Capacity maths (efficiency by mode, suggestions: lower interval / quota / widen window) | **same** | `Capacity::calculate()` is a straight port. |
| 1.21 | Live capacity pill and suggestion buttons on the settings page | **can't yet** | Teme draws a plugin's settings screen itself (no custom admin page socket). The result is shown instead as a `StatusCheck` warning on every admin page when the quota does not fit. |

## 2. Queue ordering

| # | WordPress feature | Mark | Notes |
|---|---|---|---|
| 2.1 | Shuffle the pool before scheduling | **same** | |
| 2.2 | PHP ordering: group by slug stem (US state removed), spread series | **different** | Teme's `ItemRef` has no slug (and no URL for a draft), so the stem comes from the title, slugified, which is what WordPress itself falls back to when a draft has no slug. |
| 2.3 | Spread strategies `even` (position based) and `round_robin` | **same** | |
| 2.4 | US timezone-aware ordering: state detected from title and slug, each item published inside its zone's 7 AM–11 PM overlap with the window, Random mode only | **same** | Pure logic, ported. |
| 2.5 | AI ordering with fallback to PHP ordering, response validation and reconciliation, prompt, JSON mode, temperature, max tokens | **different** | Ported on `OutboundHttp` (a `http` capability). The legacy "any OpenAI-compatible base URL" cannot be offered: the hosts must be declared in the manifest, so the provider is a `select` of declared hosts. Key is a `secret`. |
| 2.6 | AI through WordPress 7.0's `wp_ai_client_prompt()` (no stored key, provider chosen in Connectors) | **can't yet** | Teme has an AI layer of its own for core, but `teme/contracts` has no AI contract a plugin can ask for. |
| 2.7 | AI runs only on a manual run, never from cron | **same** | |
| 2.8 | AI reorder log (last 25 attempts, shown in settings, with a Clear button) | **different** | Stored in a plugin table (kept to 25). Teme has no socket to show a table on a plugin's screen, so it cannot be read in the admin yet (**wall**). The Clear button has no equivalent. |

## 3. Authors

| # | WordPress feature | Mark | Notes |
|---|---|---|---|
| 3.1 | Random author on each scheduled item | **can't yet** | `ItemChanges` has no author field, and `ItemRef` shows none. The nearest door is the `actingPersonId` that every `ContentWriter` call needs, so the plugin rotates that person (see 3.4). Whether it also changes the item's author is not documented. |
| 3.2 | Excluded authors / preserved authors (multi-selects of users) | **can't yet** | No accounts socket: no list of people, no setting type that selects them. Excluded authors are kept as a `text` list of account IDs. Preserved authors cannot work: a hook does not tell who an item's author is. |
| 3.3 | Needs "a person to act as" | **different** | WordPress runs as whoever is logged in or as the cron user. A write in Teme must name an account ID. A manual run uses the person who saved the settings (`SettingsChanged::$actorId`), a scheduled run uses a `text` setting, `acting_account`. |
| 3.4 | Random acting person among eligible authors | **different** | Eligible = the account IDs listed in a setting, minus excluded ones. |

## 4. Automation

| # | WordPress feature | Mark | Notes |
|---|---|---|---|
| 4.1 | Auto-schedule on/off | **same** | A `boolean` setting. Off by default. |
| 4.2 | WP-Cron `twicedaily` | **different** | A `schedules` entry (`hour`, the finest that makes sense for a gate) with the handler skipping unless the chosen interval (setting, default 12 h) has passed. Schedules are fixed in the manifest, so the on/off and the interval live in the handler. |
| 4.3 | Cron failure caught, error email sent | **different** | An exception in a run is caught by Teme's job runner (a failed job shows on the Jobs screen). The email cannot be sent: Teme does not hand plugins `Notices` (**proved on the dev site**: asking for it in the constructor is refused at enable). The plugin raises a `StatusCheck` warning for the failed items for 24 hours instead. |
| 4.4 | "Run Schedule Now" button | **different** | No button socket. A `run_now` toggle setting: saving it on queues the run (`SettingsChanged`). |
| 4.5 | `wp cron event run schedulely_auto_schedule` | **different** | `php artisan teme:plugins:run-due-schedules`. |
| 4.6 | Last-run timestamp | **same** | Stored in the plugin's own state table. Not displayable on a screen (see 7.1). |

## 5. Notifications

| # | WordPress feature | Mark | Notes |
|---|---|---|---|
| 5.1 | HTML email after a run with counts, windows, upcoming list | **can't yet** (replaced) | No mail door and `Notices` is not offered to plugins. In its place: an info notice on every admin page for 24 hours after a run ("last run scheduled 7 of 9 items, windows … to …"), and a warning when items failed, with the first error. The upcoming-posts list, per-window counts and the HTML layout are lost. |
| 5.2 | Recipients chosen from users with `publish_posts` | **can't yet** | No accounts socket and no way to address a notice. Status notices go to whoever opens the admin. |
| 5.3 | AI-written summary paragraph | **can't yet** | No mail, and no AI contract (2.6). |
| 5.4 | Error email | **can't yet** (replaced) | See 4.3 and 5.1. |
| 5.5 | Capacity pill / "queue is empty" / pool-overflow messages on the settings page | **different** | The same facts as `StatusCheck` notices, only when there is something to say (capacity short, auto on with no account, AI chosen with no key, timezone mode in the wrong scheduling mode, queue empty on a site that has never run, items held back by the pool size). |

## 6. Settings and surfaces

| # | WordPress feature | Mark | Notes |
|---|---|---|---|
| 6.1 | Four tab settings page (Schedule, Queue, Authors, AI & Notifications) | **different** | One settings group drawn by Teme; the declared order stands in for the tabs. |
| 6.2 | Sanitizers on every field | **same** | Teme checks `min`/`max`/`options`; the engine also clamps. |
| 6.3 | API key stored, never shown, "remove stored key" box | **different** | A `secret` setting. Teme shows only whether one is set. |
| 6.4 | Dashboard stat cards, status bar, upcoming posts list | **can't yet** | No custom admin page / dashboard widget socket. `StatusCheck` carries warnings only. |
| 6.5 | Tools → Schedulely menu entry and a Settings link on the plugin row | **same** | `contributes.admin` with `surface: tools`, `to: settings`. |
| 6.6 | Welcome notice, dismissed per user | **different** | Not built. A `StatusNotice` cannot be dismissed per person. |
| 6.7 | Defaults written on activation, version upgrade migrations | **different** | Defaults are declared in the manifest. Plugin tables come from migrations. |
| 6.8 | Uninstall removes everything | **different** | `php artisan teme:plugins:remove-data krafty-sprouts/schedulely` drops the plugin's four tables once it is disabled (**proved on the dev site**). Saved settings stay, as Teme keeps them for when the plugin returns. |
| 6.9 | "Needs an account" | **different** | New in Teme: every write names the person it acts for (see 3.3). |
| 6.10 | Multisite / Polylang / cache plugins | **same** (not needed) | Each language version is its own item; Teme purges its caches itself. |

## 7. Developer surface

| # | WordPress feature | Mark | Notes |
|---|---|---|---|
| 7.1 | WordPress Abilities (`run-schedule`, `check-capacity`, `get-furthest-scheduled-date`, `preview-next-slot`, `run-ai-reorder`) for the command palette, REST and AI agents | **can't yet** | A plugin cannot add an API endpoint, an agent tool or a command. The services behind them exist as classes in the package. The management API is for outside callers and not extensible. |
| 7.2 | Filters `schedulely_max_posts_per_run`, `schedulely_capacity_efficiency`, `schedulely_ai_*`, `schedulely_php_spread`, `schedulely_feature_*` | **different** | The values that matter are settings. Plugin-defined hooks do not exist. |
| 7.3 | Action `schedulely_clear_cache`, `schedulely_pre_ai_reorder` | **can't yet** | Teme purges its own caches on change; there is no plugin-defined hook. |
| 7.4 | Debug log to `debug.log` | **can't yet** | `teme/contracts` has no logger. |
| 7.5 | GitHub update checker | **same** (not needed) | Composer handles updates. |
| 7.6 | Translations (`.pot`) | **same** | Every word through `__()`. |
