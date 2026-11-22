# Findings: where the handbook or Teme got in the way

Kept while building. Two kinds of entry:

- **Handbook gap**: the handbook was missing something, wrong, or unclear.
- **Wall**: a socket a port like this needs does not exist yet. Per the brief, no
  wall was worked around by changing Teme; each says what was done instead.

Each entry: **Expected**, then **Did instead**. Numbers are stable; new ones are
added at the end.

## Update, 22 November 2026: Teme `main` has moved on

Everything below was written against Teme `main` as it stood on 16 November (version 0.8.0-alpha.1, contracts `@since 0.9.0`). By 22 November `main` has become the 1.0.0 line (`Prepare 1.0.0`, round 3 of the plugin sockets): the handbook now has pages for most of what was missing, and the contracts grew (`OutboundHttp::postForm`, `ContentWriter::linkTranslations`, `JobContext::personId()`, `Jobs::queue(…, QueueOptions)`). Only the handbook, references and contracts were read for this update. **Nothing in the list below has been tried in code**; it is what the pages now say, so each row is a claim to test in the next round (REPORT.md, "Next").

| Entry here | What the 22 November handbook says |
|---|---|
| H1, H10 (tables) | `plugins/tables.md` now exists. |
| H3, H14 (jobs, schedules, progress) | `plugins/jobs.md` is written: a schedule is due at the scheduler's first pass after enabling and then each time its interval has passed since it was last queued; `teme:plugins:schedules` lists them; progress shows in Tools › Jobs. |
| **H16, H17 (queue refused, schedule never due)** | **Explained.** Scheduled work runs as the administrator who enabled the plugin. A plugin with schedules enabled from the console without `--as=<administrator's email>` has schedules that don't run, and `teme:plugins` says so on its row (it does now: "Its schedules don't run: it was enabled from the command line without naming an administrator"). That is exactly how Schedulely and the seeder were enabled. `Jobs::queue()` runs a job as a person or as the operator, and an operator run has no person (`JobContext::personId()` throws). |
| H5 (no test harness) | `testing.md` now describes a testing kit with `runJob()` and `runSchedules()` against a real site. |
| H7, H15 | `teme:plugins:enable` takes `--as`. |
| W1, W12 (no read/list of content or scheduled times) | `ContentReader::queue()` lists the unpublished items the acting person may edit, oldest first, with `scheduledFor`, **including items that existed before the plugin was enabled**. `published()` lists public items. `draft()` reads one. |
| W2 (authors, accounts) | `PeopleReader::writers()` (capability `people.read`), a `person` setting type, and `credits` on `NewItem` and `ItemChanges`. |
| W3, H8 (no notices) | `plugins/notices.md`, capability `notices.send`. |
| W4 (no button or screen) | `contributes.actions` puts up to three buttons on the settings page that queue a declared job; `plugins/admin-area.md` adds screens, overview figures and record tables. |
| W5 (dynamic options) | `person`, `term` and `type` setting types. |
| W6 (fixed hosts) | `hostsFrom` lets a `url` setting name the host the plugin may reach. |
| W7 (no AI contract) | `plugins/ai.md`: `SiteAi` and `contributes.aiTasks`. |
| W9 (acting person) | `JobContext::personId()`: the person who queued the job, or for a schedule the administrator who enabled the plugin. "Don't keep a 'write as' setting of your own." |
| W10 (schedule on/off and interval) | `enabledBy` (a boolean setting) and `everyFrom` (a select setting) on a schedule. |
| W11 (10 second calls) | A job declared `long` may run 30 minutes and each outbound call 300 seconds. |
| W8 (no plugin hooks, no API) | `plugins/your-own-hooks.md` covers hooks for other plugins; whether anything exposes a plugin's actions to the management API or agents is not stated. |
| H2 (version) | Still open and worse: the dev site's `.env` still says `TEME_VERSION=0.8.0-alpha.1` while the contracts it loads are the 1.0.0 ones. |

## Handbook gaps (as found on 16 November)

### H1. Plugin migrations are not in the handbook
- **Expected**: a page saying where a plugin's migrations live, what they look like, and when they run. The Rules page says a plugin's migrations may only create tables starting with its `code` and listed under `owns`, and the manifest reference lists `owns.tables`, but nothing says how to ship a migration.
- **Did instead**: tried `database/migrations/*.php` with an anonymous Laravel migration class beside an `owns.tables` entry. It worked: enabling the plugin created the table. The `CHANGELOG.md` entry for 0.8.0-alpha.1 ("their migrations run in one transaction when the plugin is enabled … `teme:plugins:migrate` … Remove its data…") was the only written hint, and a changelog is not where a developer looks. `teme:plugins:remove-data` also exists and is not in the handbook.

### H2. The version a plugin must require cannot be required on the current `main`
- **Expected**: following [Hooks](teme-dev/docs/guides/developers/concepts/hooks.md) ("set `requires.teme` to at least the highest **Since** of anything you use"). `ContentWriter`, `Jobs`, `PluginHasJobs` and the other v1 contracts are `@since 0.9.0`, so `"^0.9"`.
- **Did instead**: the dev site on `main` is `0.8.0-alpha.1`, and Teme refuses `^0.9` there ("It needs Teme ^0.9; this is Teme 0.8.0-alpha.1."). The contracts that need 0.9.0 are already in `packages/teme-contracts` on `main` and work. `requires.teme` is `"^0.8 || ^0.9"`, which the running site accepts and a real 0.9 release will too. Changing `TEME_VERSION` in the site's `.env` was not an option: the other two plugins on that site require `^0.8` and would stop working.
- The Testing page says "set Teme's version to one the manifest accepts", which implies exactly this clash is expected, but it does not say how to do it without breaking other plugins on the same development site.

### H3. The pages for the v1 sockets are stubs
- **Expected**: `plugins/content-write.md`, `plugins/jobs.md`, `plugins/public-routes.md` and `plugins/import.md` to explain the sockets.
- **Did instead**: each is a stub saying "this page lands with … work. Until then, plugins … do not write content" / "do not declare jobs or schedules … for production use". The manifest page and the contracts say the opposite (they exist and work). The only way to learn `ContentWriter`'s and `Jobs`' behaviour was reading the interface docblocks in `teme/contracts`. The questions the stubs leave open are listed under W-entries below (who is "the person", what a schedule run's actor is, how a job is triggered).

### H4. The manifest page leaks an internal branch name
- **Expected**: user-facing text.
- **Found**: [The manifest](teme-dev/docs/guides/developers/concepts/manifest.md) says the four v1 capabilities "are joined on the `join/v1-wave1` branch". An outside developer cannot tell whether that means the sockets are on `main`, in a release, or unreleased. They are on `main` today.

### H5. No test harness a plugin can use on its own
- **Expected**: a way to run a plugin's tests from the plugin's folder (the For agents page asks for "how to run its tests" in `AGENTS.md`).
- **Did instead**: the Testing page describes Teme's own `tests/Feature/Guides/Developers/ExamplesTest.php` and fixtures under `tests/Fixtures/Plugins`, which live in the Teme repository and are about Teme's own suite. `composer.json` in Teme has a `check:plugins` script naming a `Plugins` testsuite, but `phpunit.xml` has no such suite. There is no `teme/testing` package for plugin authors. Schedulely's tests are plain Pest tests run with the dev site's `vendor/bin/pest` and its own `phpunit.xml`, with pure logic tested without booting Teme, and a smoke script for the real site (see REPORT.md).

### H6. Handbook says `@since` only for packages that publish an API
- **Found**: [Code standards](teme-dev/docs/guides/developers/start/code-standards.md) says `@since` is only recommended for packages with an API of their own. This package carries `@since` on every class and method and a file header in every PHP file because the project owner asked for it ("Decisions", D6).

### H7. `php artisan teme:plugins:enable` on an updated manifest
- **Found**: changing a manifest to ask for more access leaves the plugin in `Needs attention` ("Update 0.1.0 is blocked: it asks for more access than you agreed to.") until `teme:plugins:enable --agree` is run again. The Rules page says this in one sentence ("one that asks for more waits until someone agrees again"); it does not say how, and a plugin whose version string did not change is easy to take for a bug.

### H8. The Notices contracts look like a plugin socket and are not one
- **Expected**: `teme/contracts` has `Notices`, `NoticeRegistrar`, `NoticeKind` and `NoticeMessage`, documented as "Where kinds of notice are declared" and "Sends a notice of a declared kind". A plugin author would take them as a plugin socket.
- **Found**: asking for `Notices` or `NoticeRegistrar` in a plugin constructor is refused at enable: "asks for Teme\Contracts\Notices\Notices; a plugin may ask only for what Teme offers plugins." They are for core code only (the docblock says "A preview until Teme 1.0"). See W3.

## Walls

### W1. No way to read or list content
- **Expected**: Schedulely's whole job is "take the items in a status (draft/review) and give them times". WordPress does it with `get_posts()`. A plugin with `content.read` needs a door that lists items by status and type, oldest first, and counts items by status and date range; and `content.write` needs to find "what is already scheduled, and when".
- **Did instead**: kept the plugin's own queue and slot tables, filled by listening to `ContentSaved`, `ContentUnpublished`, `ContentRestored`, `ContentTrashed`, `ContentDeleted`, `ContentScheduled` and `ContentPublished`. This is correct for everything that happens after the plugin is enabled and wrong for everything before it: drafts that already exist are invisible until someone saves them again, so a site that installs Schedulely on a backlog of 2,000 drafts starts with an empty queue. Content written by an import never reaches a hook ("never during an import run"), so imported drafts stay invisible too.

### W2. No author on writes, no author on reads, no list of accounts
- **Expected**: `ItemChanges` / `NewItem` take an author, `ItemRef` tells the author, and some socket lists the site's people so a setting can offer them (excluded authors, preserved authors, email recipients).
- **Did instead**: random author assignment is replaced by rotating the `actingPersonId` that every `ContentWriter` call requires, among account IDs typed into a text setting. Whether acting as a person makes them the item's author is not documented. Preserved authors cannot be built at all, because nothing tells the plugin who an item's author is. Account IDs are typed by hand because no setting type chooses people.

### W3. No way to send a notification or an email
- **Expected**: WordPress sends an HTML email after each run and on errors, to chosen users. `Notices` exists in `teme/contracts` (H8) but is not offered to plugins, and there is no mail door.
- **Did instead**: a `StatusCheck` notice shown on every admin page for 24 hours after a run (and for errors), through `ChecksStatus`. It cannot name recipients and cannot be dismissed.

### W4. No custom admin screen, button, dashboard card or table view
- **Expected**: a "Run Schedule Now" button, stat cards, a live capacity pill, the upcoming list, the reorder log table, a Clear-log button.
- **Did instead**: Teme draws the settings group from the manifest and nothing else. A `run_now` toggle setting stands in for the button (saving it on queues a run); capacity is checked into a `StatusCheck` warning; stats and the log are stored but cannot be seen in the admin.

### W5. Settings cannot offer a choice that depends on the site
- **Expected**: post types and people as multi-selects.
- **Did instead**: `text` settings holding content-type keys and account IDs. A manifest `select` has fixed options.

### W6. Outbound hosts are fixed in the manifest
- **Expected**: WordPress lets the user type any OpenAI-compatible base URL for AI ordering.
- **Did instead**: a `select` of three providers whose hosts are declared under `hosts` (DeepSeek, OpenAI, OpenRouter). Any other service needs a new release.

### W7. No AI contract
- **Expected**: WordPress 7.0's `wp_ai_client_prompt()` lets a plugin ask for text from whichever provider the site connected. Teme has an AI layer for its own features (`laravel/ai`, settings under AI) but `teme/contracts` has no AI interface.
- **Did instead**: AI ordering goes through `OutboundHttp` to the declared provider with a key stored as a plugin secret, so the administrator pastes a key Teme already holds elsewhere. The AI summary in the email is dropped (no mail either).

### W8. No plugin-defined hooks, no way to extend the API, commands or agent tools
- **Expected**: WordPress Abilities expose five actions to REST, the command palette and agents; developer filters `schedulely_*` let sites change behaviour.
- **Did instead**: the filtered values became settings; the Abilities are plain classes in the package (`Capacity`, `SlotPlanner`, `QueueOrderer`) with tests, not reachable from outside the PHP process.

### W9. A scheduled job has no acting person
- **Expected**: `ContentWriter` needs `actingPersonId`. A manual run has one (`SettingsChanged::$actorId`), a scheduled job run by `teme:plugins:run-due-schedules` has none.
- **Did instead**: a text setting `acting_account`. If it is empty a scheduled run records a clear error and does nothing; there is no "act as the site's first administrator" default because a plugin cannot look one up.

### W10. A schedule cannot be switched off or retimed by a setting
- **Expected**: WordPress has an Auto-schedule toggle that adds or removes the cron event and a fixed `twicedaily` interval.
- **Did instead**: the manifest declares an hourly schedule once; the handler reads `auto_schedule` and `auto_interval` and returns early.

### W11. A plugin's HTTP call is limited to 10 seconds and 1 MB
- **Expected**: WordPress waits up to an hour for the AI to order a queue of up to 1,500 posts (a reorder of a few hundred takes minutes on a reasoning-capable model).
- **Did instead**: AI ordering sends at most 150 items in one request (a setting), numbered `1…n` with a slug each instead of ULIDs to keep the prompt short, and a larger queue is ordered in PHP with a log row saying why. Chunking the queue would lose the global spacing, so it is not done. `OutboundHttp::postJson()` also gives no way to ask for a longer wait, so a slow model can still time out; the fallback to PHP ordering covers that.

### W12. A plugin cannot read when an item is scheduled for
- **Expected**: a way to ask "when is this item due to publish?", or a `publishAt` on `ItemRef`. Schedulely's deficit rule ("is the furthest day short of its quota?") needs the times of every scheduled item.
- **Did instead**: the ledger table is filled from `ContentScheduled` (which carries `publishAt`) and emptied by the publish, unpublish, trash and delete hooks. It misses everything scheduled before the plugin was enabled, items scheduled by an import, and an item restored from the trash while scheduled (`ContentRestored` carries no time). On a site that already has scheduled items, the first run can therefore start on a day that is already fuller than the plugin believes, and the real count per day will exceed the quota until those items publish.

## More handbook gaps

### H9. There is no way to put test content on a development site
- **Expected**: the development-site page ends at "enable your plugin"; a plugin that reacts to content needs content. A command, a factory, a seeder or a documented way to make drafts.
- **Found**: the console has no command that creates an item (`teme:media:add`, `teme:people:add`, `teme:user:create` exist; `teme:bench:seed` fills a separate benchmark database). The management API can create drafts but needs a key made by an administrator in the admin, which needs a signed-in session. Teme's own application code is off limits to an outside developer, and was not read.
- **Did instead**: everything that does not need a real item was run for real on the dev site (manifest check, constructor doors, tables and migration, enable, remove-data, `teme:doctor`). The hook-to-queue path and `ContentWriter::schedule()` were tested against fakes of the contracts only. **The scheduling run has not been seen creating a real scheduled item.** REPORT.md says so.

### H10. How a plugin reaches its own tables is not written down
- **Expected**: a sentence next to "Tables carry the plugin's code" saying how code reads and writes them. The rules say a constructor may ask only for what Teme offers (`SettingsReader`, `SecretReader`, `OutboundHttp`, and the doors capabilities open); a database connection is not among them.
- **Did instead**: `Illuminate\Support\Facades\DB::connection()`, called when a hook or job runs, never in the constructor. Teme's enable-time scan accepted it, so it works, but "never reaches for the container" (Rules page) reads as if a facade might be refused. Whether this is the intended way is unknown.

### H11. Where a plugin's translations go, and what they are keyed by
- **Expected**: "Keep the translations in the package's own `lang` folder, as themes do" (Rules) with a page saying how Teme finds them (a `lang` folder at the package root? JSON keyed by the English sentence? `:name` placeholders?). The only page cited is for themes.
- **Did instead**: every sentence goes through a small `Words::say()` that calls `__()` with the English sentence as the key and `:name` placeholders, falling back to plain English when no translator is running. No `lang` folder is shipped because nothing says what Teme would read from it.

### H12. The value a `multiselect` setting gives back
- **Expected**: the Settings page says `stringList()` answers `list<string>`, and a `multiselect` `default` is "any JSON value". Not said: whether the saved value is the option keys as strings, whether a day option keyed `"0"` survives as `"0"`, and what an empty selection returns.
- **Did instead**: the weekday setting uses string keys `"0"` to `"6"` and `Options` accepts strings or integers, ignores everything out of range, and treats an empty answer as "every day". Not seen on a live site (H9: no signed-in session to save a setting).

### H13. What `ContentWriter::schedule()` means is only in the interface's one-line docblock
- **Expected**: the (stub) content-write page: whose name the revision carries, what happens to an item in `review`, whether the item's status is `scheduled` afterwards, what a `WriteRefused` can say, and whether `ContentScheduled` has fired by the time the call returns.
- **Did instead**: assumed hooks fire after commit as the Hooks page says, so a write is followed at once by the plugin's own ledger update (`WriterItemScheduler`); the hook later writes the same row again, which is harmless. Everything else is untested against Teme.

### H14. Jobs and schedules: who runs them, and where progress shows
- **Expected**: a Jobs page (it is a stub, H3) saying the schedule is driven by `php artisan teme:plugins:run-due-schedules`, that a queue worker must be running, and where a job's `progress()` message is shown.
- **Found**: `teme:doctor` says "The scheduler hasn't run on this site yet… Start the scheduler role, which runs `php artisan schedule:work`" and that a worker finished a job an hour ago. The finest schedule a manifest can ask for is `minute`; Schedulely uses `hour` and decides the rest itself (W10). Progress was assumed to show on Tools › Jobs from the 0.8.0 changelog; not seen.

### H15. `php artisan teme:plugins:enable` re-reads the manifest every time
- **Found** while iterating: after each change to `teme-plugin.json` the plugin shows `Needs attention` or `Can't be used` until it is enabled again, and the `Why` column is the only place that says why (it names the missing import in a constructor, for one). That column is good; the Testing page could say to read it.

### H16. `Jobs::queue()` refuses unless a person or "the operator" is acting
- **Expected**: the Jobs contract says "Queues one of the calling plugin's declared jobs"; the stub Jobs page says nothing more.
- **Found** (second attempt to get live content onto the dev site, after the owner agreed to a throwaway account and a seeder plugin): calling `queue()` from `register()` threw `InvalidArgumentException: Only a person or the operator queues a plugin job.` in the console, including inside `php artisan teme:plugins:run-due-schedules`. The error is in the site's log only; the plugin still showed as Enabled. This is consistent with Schedulely's design (a "Run now" saved by a person queues; a hook with no person could not), but it means a plugin cannot queue its own work from a hook caused by anything automatic, and the docblock does not say so. It also means `RunNowTrigger` runs as nobody when `SettingsChanged::$actorId` is null, and would be refused.

### H17. A plugin schedule never came due on the dev site, and nothing says why
- **Expected**: a `schedules` entry of `every: minute` queues its job about once a minute while the scheduler runs.
- **Found**: three and a half minutes of polling `teme:plugins:run-due-schedules` (and one `schedule:run`) answered "No plugin schedules were due." for two plugins, one of them re-enabled a minute earlier. `teme:doctor` says the scheduler role has never run on this site, which may be the whole reason; the docs do not say what makes a schedule due, whether the first run waits a full period, or whether `run-due-schedules` can be used by hand to test one.
- **Effect on this port**: the hourly `auto-run` job was never seen firing, and a throwaway seeder plugin that was meant to put drafts on the site (through `ContentWriter::create`) could not be started by either route. **No live scheduled item was produced.** The seeder, its `.env` path and the temporary manifest defaults were removed; the throwaway account `schedulely-dev@example.invalid` (an editor, user ID 3) still exists on the dev site, with its unused set-password link, for the owner to delete in the admin.
