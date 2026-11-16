# Decisions

Made without asking, each with why. Newest at the end.

**D1. Source of truth for Teme.** Only `teme-dev/docs/guides/developers/` (handbook), `docs/reference/` (generated), and `packages/teme-contracts/` (contracts and schema) were read. Not read: `docs/plans/`, `docs/proposals/`, `docs/architecture/`, Teme's `app/`, and the other two plugins on the site. Teme's `CHANGELOG.md` was read once for H1 (migrations) because it was the only place the answer was written down; this is recorded as a handbook gap. The `docs/plans/plugin-sockets-2.md` file that arrived with `git pull` was left unread on purpose: a developer outside the project would not use a plan to build against.

**D2. Updating teme-dev.** `git pull` brought two commits that only added planning documents. `composer.lock`, `package-lock.json` and every source file were unchanged, so `composer install` and `npm ci && npm run build` had nothing to do. The `composer install` that was started hung for eight minutes with no output (it was not the database or a lock: the site, `php artisan --version` and `migrate` all worked) and was stopped; the build output in `public/build` was already current. `php artisan migrate` said "Nothing to migrate". No other port session was running when this was done (checked in the session list), and the AdPlacr and Writura plugins were left enabled and untouched.

**D3. `TEME_PLUGIN_PATHS`** has the absolute path of this folder appended after the two existing entries, comma separated. No other line of the site's `.env` was changed; in particular `TEME_VERSION` stays (see FINDINGS H2).

**D4. `requires.teme` is `"^0.8 || ^0.9"`.** See FINDINGS H2. It is the narrowest constraint that both the running development site and a 0.9 release accept.

**D5. Plugin identity.** Composer name and manifest `name`: `krafty-sprouts/schedulely`; `code`: `schedulely`; `author`: `Krafty Sprouts Media, LLC`; PHP namespace `KraftySprouts\Schedulely`; settings group `plugin.schedulely`; tables `schedulely_*`; the license stays GPL-2.0-or-later like the WordPress plugin.

**D6. Headers and `@since`.** Every PHP file starts with a file-header docblock (filename, author, description, package, since; the project owner later dropped the Created and Last Modified lines as unnecessary, so they are not used) and every class, interface, public method and constant has `@since 0.1.0`, as the project owner asked. The handbook only recommends `@since` for packages with their own API, so this goes beyond it. The `@since` in this package is this package's own version (0.1.0 is the first Teme release; the WordPress plugin's 1.8.15 history is not carried over).

**D7. `declare(strict_types=1)` and one-to-three-sentence class docblocks** everywhere, as the handbook recommends. Pint and PHPStan are used where they run (see REPORT.md).

**D8. Tests.** Pest, a failing test first for each feature (the red run is shown in the commit message when it fails for the right reason). Pure logic is tested without booting Teme. Storage is tested against in-memory SQLite through `illuminate/database`'s own `Capsule`, running the plugin's own migration files, so the SQL in this package is real. A smoke script drives the live development site. The Laravel test case from Teme's own suite is not used: Teme's `phpunit.xml` points at the site's own database, and a plugin test that refreshed it would wipe the site.

**D9. Randomness and time are injected.** `Random\Randomizer` and a `Clock` are constructor arguments so every algorithm is deterministic under test. WordPress's `rand()`, `shuffle()` and `time()` calls were the only non-determinism in the engine.

**D10. The queue is Schedulely's own table** (FINDINGS W1). It stores the ID, type, title, language, status and when Schedulely first saw the item. "Oldest first" means oldest first seen.

**D11. Slot-based and random placement keep WordPress's exact rules**, including its quirks: Sequential/Hybrid ignore the minimum interval; a failed slot counts toward the day's quota so the day advances; the safety buffer is applied to the start of the usable span.

**D12. WordPress options that were filters** (`schedulely_schedule_safety_buffer_seconds`, `schedulely_max_posts_per_run`, `schedulely_ai_reorder_*`) are settings or fixed constants. Where it was a developer-only filter with a sensible fixed value (AI temperature 0.3, 64K token ceiling, 3600 s timeouts), it is a constant in `Defaults`.

**D13. AI ordering sends only what WordPress sent** (ID and slug-like text per line, no content), through `OutboundHttp`. The provider API key is a `secret` setting and is never logged or stored in the reorder log. No real key appears in any file or test; tests use a fake `OutboundHttp`.

**D14. The "Run now" trigger is a toggle** (`run_now`), run when its saved value is on (FINDINGS W4). The plugin cannot reset its own setting, so the person turns it off and on to run again; documented in the setting's description.

**D15. Auto-schedule runs hourly and gates in the handler** (W10). The WordPress twice-daily rhythm is the default interval (12 hours); the person can pick 1, 6, 12 or 24. A tick that comes up to five minutes early still counts as due, so a 12 hour interval does not drift to 13.

**D16. The queue tracks every content type and both waiting statuses**, not only the ones currently chosen, so changing "Content types to schedule" or "Status to schedule from" later finds items that were already seen. Only an item's current status and type decide whether a run takes it.

**D17. A manual run is a background job, not a request.** It reports progress every "progress batch size" items (setting, default 75, as WordPress's batch size), and cancelling it (JobStopped) leaves what was scheduled in place and the rest queued. A retry after a crash simply continues, because scheduled items have left the queue.

**D18. Scheduled items are written one `ContentWriter::schedule()` call each,** and the plugin writes its own ledger row straight after, rather than waiting for `ContentScheduled`, so the rest of the same run counts the item. The hook later writes the same row again (an upsert), which is harmless.

**D19. AI ordering sends numbers, not IDs.** Teme's item IDs are 26-character ULIDs; numbering the lines 1…n keeps the prompt short and makes "no invented IDs" a range check. At most `ai_max_items` (default 150) per request because a plugin's call may take ten seconds (FINDINGS W11).

**D20. No notification email.** Teme offers none to a plugin (FINDINGS W3, H8). The status notices stand in; they are quiet unless there is something to say (an always-on "everything is fine" notice would be noise on every admin page).

**D21. The author feature is a rotation of the acting person** (FINDINGS W2), not author assignment. It is off by default, and its setting says plainly that Teme does not document whether it changes the author. Preserved authors are not built.

**D22. Formatting.** `pint.json` is Teme's own Pint configuration with two rules turned off, `phpdoc_no_package` and `blank_line_after_opening_tag`, because they would strip the file header the project owner asked for. PHPStan runs at level 6 with no errors. Both run with the development site's binaries.

**D23. The live check is limited to what the public console can do** (FINDINGS H9). A permission check refused reading Teme's own application folder when I looked for a way to create content; the brief limits sources to the handbook, references and contracts, so that stands, and no workaround was tried.

**D24. The plugin is left enabled on the development site** with its tables, as the other two plugins are. Disabling it and `teme:plugins:remove-data` undo it fully.
