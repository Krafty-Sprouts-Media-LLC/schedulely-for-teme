# Findings: where the handbook or Teme got in the way

Kept while building. Two kinds of entry:

- **Handbook gap**: the handbook was missing something, wrong, or unclear.
- **Wall**: a socket a port like this needs does not exist yet. Per the brief, no
  wall was worked around by changing Teme; each says what was done instead.

Each entry: **Expected**, then **Did instead**. Numbers are stable; new ones are
added at the end.

## Handbook gaps

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

### H8. `list` of Notices doors is previewed but not offered
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
