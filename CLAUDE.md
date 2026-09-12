# Kerry Football Admin

A WordPress plugin for running a **private fantasy-football pick'em league**. Players pick winners each week and assign point values to each pick; a commissioner enters results and finalizes the week; scores, awards, and standings are computed from there.

> **This project is unrelated to Silvarians Notes** (the D&D campaign-tools plugin, `silvarians-game-tools`, in a sibling folder under `Documents\Claude\`). They share nothing — no code, no database, no conventions, no version numbers. Never apply Silvarians release protocol, version numbers, memory, or file layout here. If a Silvarians path appears while working in this repo, something is wrong.

- **Repo root:** `Kerry Football\Code\kerry-football-admin` (this folder is the plugin directory and the git root)
- **Remote:** `github.com/mjt1st/Kerry-Football`, branch `main`
- **Version:** `1.8.13` (plugin header in `kerry-football-admin.php`)
- **DB schema version:** `1.3` (WP option `kf_db_version`)
- **Author line:** `Kerry/Gemini` — much of the codebase was written by Gemini; comments carry version tags like `V2.1.6`, `SPORTS API V1`, `LATE PICKS V2.1` that do **not** match the plugin version. Treat them as change markers, not versions.

## Domain vocabulary

Read this before touching scoring — the abbreviations are league jargon, not standard fantasy terms.

| Term | Meaning |
|---|---|
| **Season** | A league instance. Everything is scoped to a season. `sport_type` is `nfl` or `college-football`. |
| **Week** | Statuses: `draft` → `published` → `finalized`, plus `tie_resolution_needed`. |
| **Matchup** | One game in a week. Exactly one per week has `is_tiebreaker = 1`; its `result` is a numeric score, not a team name. |
| **Pick** | A player's chosen team for a matchup plus a `point_value` (whole numbers, unique per week — the weekly pool is `weekly_point_total`). |
| **MWOW** | *Most Wins Of Week*. Decided on `wins`. Winner gets `mwow_bonus_points`. |
| **BPOW** | *Best Points Of Week*. Decided on `subtotal`. The BPOW winner gets to submit a **second** set of picks the following week (`picks.is_bpow = 1`). |
| **Double Down (DD)** | A player spends a DD to overwrite their **lowest-scoring finalized week** with the current week's subtotal. Capped at `dd_max_uses`, unlocked from `dd_enabled_week`. |
| **Late picks** | Post-deadline submissions land in `pending_picks` for commissioner approval, not in `picks`. |

Scoring rules that are easy to get wrong:
- A `result` of `tie` / `t` / `draw` awards `FLOOR(point_value / 2)` points and **zero** wins.
- Awards (MWOW/BPOW) are decided from **regular picks only** (`is_bpow = 0`). BPOW picks can only replace that one player's own score.
- The BPOW second set replaces the regular score only if `bpow.subtotal > regular.subtotal + mwow_bonus`, and when it does, the MWOW bonus is **not** awarded.
- Tie-break for awards is the smallest `tiebreaker_diff`; a remaining tie sets the week to `tie_resolution_needed` and requires the commissioner to pick.

## Architecture

Classic procedural WordPress plugin. **No classes, no namespaces, no autoloader, no build step, no tests.**

- `kerry-football-admin.php` — plugin header, `require_once` of every include, AJAX handlers, activation/deactivation hooks, blocked-user login filter.
- `includes/kf-*.php` — one file per concern. Files named `*-view.php` render a front-end page; most also handle their own `$_POST` at the top of the render function.
- `assets/css/kf-styles.css`, `assets/js/kf-table-controls.js`, `assets/js/kf-game-browser.js` — edited directly, no compilation.

**The front end is shortcode-driven.** Every page is a WordPress page containing one shortcode; all shortcodes are registered in `includes/kf-shortcodes.php` (`kf_homepage`, `kf_player_picks`, `kf_week_setup`, `kf_season_summary`, `kf_enter_results`, …). A new page means a new shortcode there plus a WP page created in wp-admin. There is no routing layer.

**Active season lives in `$_SESSION['kf_active_season_id']`**, not in the URL. `kf_start_session_early()` starts the session on `init` priority 1 — every `session_start()` in this codebase is guarded by `session_status()` and `!headers_sent()` because unguarded calls previously caused "headers already sent" login crashes. Keep that guard on any new call. `includes/kf-season-switcher.php` owns the session, validates it against the user's memberships each request, and exposes the AJAX season switcher wired into the nav menu by `includes/kf-menus.php`.

**Nav integration is filter-based:** menu items get CSS classes in wp-admin (`kf-season-switcher-placeholder`, `kf-week-summary-placeholder`, `kf-commissioner-only`, `kf-player-only`) and `kf-menus.php` rewrites or hides them via `wp_nav_menu_objects`. `kf-week-summary-placeholder` is retitled and pointed at the active season's newest non-draft week (`/week-summary/?week_id=N`) on every request, because that id changes weekly; the item is dropped when the season has no visible week. The homepage season cards carry the same link (`summary_week` in `kf_get_card_data_for_season()`), which is the only route to a week summary that does not require knowing the id.

## Permissions

Three helpers in `includes/kf-season-switcher.php` are the only correct way to check access:

- `kf_can_access_season( $season_id, $user_id = null )` — **may this user look at this league at all**: admin, creator, or any accepted member. Use for the active-season session, season switching, and read-only views keyed to a season. It grants no management rights.
- `kf_can_manage_season( $season_id, $user_id = null )` — commissioner rights for **one** season.
- `kf_is_any_commissioner( $user_id = null )` — commissioner of **at least one** season; use to gate pages with no season context yet.

⚠️ Until 1.8.13 the manage check also guarded season switching and the session's active season. Ordinary players are accepted members with `is_commissioner = 0`, so they failed it: their chosen season was cleared on every request and reset by the fallback query, the switcher AJAX answered "You are not a participant in this season", and the week summary refused every week outside that one season. A player in two leagues could only ever see one. Use the access helper for anything that is about *which league is being viewed*.

A user qualifies if they have `manage_options`, created the season (`seasons.created_by`), or are an accepted member flagged `season_players.is_commissioner = 1` (the co-commissioner role). Note the docblock on `kf_can_manage_season` still says site admins are *not* auto-granted — that is stale; the code grants them and commit `6db1bcc` made that deliberate.

Never gate league features on `current_user_can('manage_options')` alone — that locks out co-commissioners, which was the bug behind `52de52a`. `manage_options` is correct only for genuinely site-level things: the admin dashboard, API settings, and granting/revoking co-commissioner (deliberately restricted to admins and season creators so co-commissioners cannot escalate each other).

## Database

All tables are created by `kf_install_db()` in `includes/kf-database-setup.php` via `dbDelta` on activation. That function is the schema source of truth.

⚠️ **Table names are unprefixed nouns behind `$wpdb->prefix`** — `{$wpdb->prefix}seasons`, `weeks`, `matchups`, `picks`, `scores`, `season_players`, `season_player_order`, `score_history`, `double_down_log`, `dd_selections`, `pending_picks`, `notification_settings`, `api_usage`. There is **no `kf_` in the table names.** On the live site `$wpdb->prefix` is `edk_`, so these read as `edk_seasons` etc. Always write `{$wpdb->prefix}seasons` — never hardcode `wp_` or `edk_`.

⚠️ **Saving a week deletes and re-inserts every matchup**, assigning new AUTO_INCREMENT ids. `picks.matchup_id` references those ids, so rewriting a week that already has picks orphans all of them. `kf-week-setup.php` refuses the matchup write when `COUNT(picks) > 0` — keep that guard. Note published weeks are normally locked, but *repair mode* (published week with a missing `matchup_count`) re-opens the same destructive path.
- **Week snapshots** (`includes/kf-week-snapshots.php`, table `{$wpdb->prefix}week_snapshots`, schema v1.2) capture week + matchups + picks + scores + pending_picks as JSON before finalize, before reverse, and before a matchup rewrite. Picks are the only unrecoverable part of a week — scores are derived and matchups are re-fetchable — so that is what this protects. `score_history` does NOT cover it; that table is Double Down bookkeeping only. Snapshots are capture-only: restoring is deliberately manual, because a rewrite changes matchup ids and picks would need re-mapping.

**Adding a column:** update the `CREATE TABLE` in `kf_install_db()` *and* add a guarded block to `kf_maybe_upgrade_db()`, then bump the version string in both the `version_compare` guard and the closing `update_option( 'kf_db_version', … )`. `kf_install_db()` only runs on activation, so without the upgrade block a live update silently leaves the column missing — that is exactly what `b7f5045` had to fix.

Direct `$wpdb` calls everywhere. Always `$wpdb->prepare()` interpolated values; the existing code does, and several commits (`fd2c0c2`) exist purely to fix places that didn't.

## External APIs

- **ESPN scoreboard** (`includes/kf-sports-api.php`) — free, unauthenticated. Powers the commissioner game browser and live scores.
  ⚠️ **ESPN sits behind Akamai, which blocks by User-Agent — and keeps tightening.** WordPress's default (`WordPress/6.x; https://site.com`) has never worked. `PHP/<version>` and sending no User-Agent both worked in March 2026 and were blocked by Aug 2026. Because any single hardcoded string is a future mid-season outage, `kf_espn_remote_get()` now **rotates a candidate list**, remembers the winner in `kf_espn_working_user_agent`, and only rotates on 403 (other errors are returned as-is). A commissioner can override it from API Settings (`kf_espn_user_agent`) without a plugin update. Do **not** add a `Mozilla/...` string: Akamai compares it to the PHP/cURL TLS fingerprint and treats the mismatch as an impersonating bot. Known good as of 2026-08-15: `curl/*`, `GuzzleHttp/7`, `python-requests/*`, `Go-http-client/1.1`, `okhttp/*`, `libwww-perl/*`. Known blocked: `WordPress/*`, `Mozilla/*`, `PHP/*`, `Wget/*`, `PostmanRuntime/*`, `node-fetch/*`, and no-UA. Failures are not cached, so a fix takes effect immediately. A second host, `site.web.api.espn.com`, serves byte-identical payloads and is **not** behind the same ruleset (it accepted every UA tested, including WordPress's). `kf_espn_remote_get()` falls back to it and remembers the working host in `kf_espn_working_host`. Community reports place the tightening on **2026-08-05**; consensus is bot protection, not deprecation.
- **The Odds API** — integrated but not on the active path; kept for bookmaker-specific lines. It is credit-metered, which is why `api_usage` exists.
- `includes/kf-score-cron.php` schedules automatic score refreshes; `kf_unschedule_score_cron()` runs on deactivation.
  Score refresh has three layers: the `kf_check_game_scores` cron every **15 min** (WP-Cron, so it only fires when the site gets traffic), the manual **Refresh Scores Now** button on Enter Results, and a **15-min scoreboard / 5-min single-event transient cache**. Both the cron and the manual path must `delete_transient()` before fetching — a 15-min cache on a 15-min schedule otherwise serves the previous run's data.
  The cron **skips the ESPN call entirely when no tracked game could be underway** — a game counts from kickoff until 6h after, and games with no known kickoff always count so a missing date cannot silently stop updates. Gate on the data, never on time-of-day: the same runner fires every other WP event, deadline reminder emails included, so narrowing the cron window would delay those too.
  ⚠️ The cron **never overwrites a `result` that is already set** — a commissioner override on Enter Results always wins, because ESPN cannot model forfeits, abandoned games or a bad feed. Scores and `game_status` still refresh. Clear the result field to let the cron repopulate it. Unlink via the linker panel to stop a game updating entirely.

## Conventions

- Everything is prefixed `kf_` — functions, options, AJAX actions, nonces, CSS classes.
- Every file starts with `if ( ! defined( 'ABSPATH' ) ) exit;`.
- `kf-enter-results-view.php` was written with **curly quotes** (`class=”x”`) throughout its markup, which browsers parse as part of the attribute value — the "Refresh Scores Now" button never fired because `type=”button”` is not a valid type and its `onclick` was not valid JS. Repaired in 1.5.4; watch for the same in any file that came from a word processor.
- `kf-enqueue-scripts.php` omits the closing `?>` deliberately — stray whitespace after it caused blank-screen bugs. Don't add one back.
- **Nonces are per-form, not global.** `kf_add_player_nonce`, `kf_submit_picks_nonce`, `kf_finalize_week_nonce`, `kf_results_nonce`, etc. Only two are used for AJAX: `kf_season_switcher_nonce` (the one localized to JS as `kf_ajax_data.nonce`) and `kf_ajax_nonce`. Match the existing name for the surface you're touching rather than inventing one.
- Asset versions use `filemtime()` so caches bust automatically — keep that pattern (`e52e0d0`).
- `error_log()` is the debugging channel, especially in the Double Down engine.
- Commit messages: short imperative summary, no prefix convention, occasional `Area: detail` form.

## Gotchas

- `kerry-football-admin BACKUP.php` is an untracked-in-spirit stale copy of the main file. It is **not** loaded. Don't edit it and don't treat it as reference.
- `assets/css/kerry-football-styles.min.css` is **not enqueued** — dead file. Only `kf-styles.css` ships.
- `Vendor/PhpSpreadsheet/` is vendored but **not referenced anywhere** in plugin code. No composer autoloader exists. Don't assume spreadsheet support works.
- Manual matchups carry no `espn_game_id`, so the score cron cannot update them. The week-setup form offers **suggested** ESPN matches per unlinked matchup (client-side, against the fetched game list) and fills the same hidden inputs the browser writes — suggestions are never auto-applied, because a wrong link feeds the wrong score into a finalized week. Every matchup fieldset must emit ALL of the hidden `espn_game_id[]`/`spread_home[]`/etc. inputs even when empty: the save handler reads them positionally against `team_a[]`.
  A matchup fieldset carries a **Remove** button in its legend (draft weeks only), rendered in all three places a fieldset is built: the PHP loop, `createMatchupFieldset()` in the week-setup inline script, and `addSelectedGames()` in `kf-game-browser.js`. Removal deletes the whole fieldset, which keeps every positional array aligned — but `tiebreaker_marker`'s value is a hard-coded index, so `renumberMatchups()` must rewrite it against the new positions, and re-check Matchup 1 when the removed game held the flag (the radio is `required`; with none checked the form refuses to submit and says nothing about why). The week-profile observer's `refreshAll()` also re-runs `refreshAddedState()`, so a removed ESPN game's card in the browser becomes selectable again, and the remove handler sets `window.kerryFootballFormDirty` because removing a node fires no change event.
  ⚠️ ESPN's `details` string names the favourite with the real abbreviation, punctuation included — `TA&M -14.5`. Match it as "everything before the trailing number", never as a character class; a class that missed the punctuation left `spread_home` null while the card still showed a spread, so the week read "No odds" for a game that had them.
  Name matching runs four tiers: exact, substring, **word-prefix** (every word in the stored name must prefix-match a word in the ESPN name — this is what makes `OK STATE`/`Oklahoma St` and `WASH STATE`/`Washington St` line up), then mascot. Verified 15/15 on real Week 1 data with one candidate each. Keep the PHP scorer (`kf_espn_name_score`) and the JS one (`kfTeamScore`) in step — they are deliberate duplicates.
  ⚠️ ESPN's **summary** endpoint has no `header.gameDate`; the kickoff is at `header.competitions[0].date`. Reading the wrong key stored an empty `game_datetime` for every linked game (fixed 1.6.1). `kf_espn_fetch_scores()` must also pass `groups=80` for college, or the scoreboard returns only ESPN's default selection and every game falls through to a per-game request.
  Live period/clock comes from ESPN `status.type.shortDetail` (`"9:55 - 3rd"`) and is stored in `matchups.status_detail` (schema v1.3). Only saved while `game_status === in_progress` — for a scheduled game shortDetail is a kickoff time in ESPN's own timezone, which the plugin already stores and renders itself.
- ⚠️ **Score fetches are per sport, and the sport comes from the season** (`kf_group_event_ids_by_sport()` for the cron, the week's own season for the manual refresh). Until 1.8.11 both fell back to the site-wide `kf_default_sport` whenever more than one season had pending games, so a site running an NFL season and a college season together looked both up on one scoreboard and every game in the other sport came back "not found at ESPN" — silently, for the whole season, because not-found is not an error. The cron report on the Commissioner Dashboard names the sports it asked.
- ⚠️ An ESPN **timeout** is invisible unless it is logged: `wp_remote_get()` returns `WP_Error`, `kf_espn_fetch_scoreboard()` passes it up, and every caller reads the empty result as "no games". The full FBS scoreboard is ~1.5 MB, so a slow host really does hit this; the timeout is 25s and transport failures are logged in `kf_espn_remote_get()`.
- ⚠️ `kf_install_db()` table definitions must carry **no inline `--` comments**. dbDelta parses the CREATE TABLE line by line and emitted a broken `ALTER TABLE edk_picks CHANGE COLUMN ...` on every activation, logged as a SQL syntax error.
- Menu items injected by `kf-menus.php` must set `current`, `current_item_ancestor`, `current_item_parent`, `target`, `xfn`, `description`, `post_parent` and `object_id`; `Walker_Nav_Menu` reads them and logs a PHP warning per item per page load otherwise.
- To attach an ESPN game to a week that already has picks, use the **link-only** path: AJAX `kf_espn_link_suggestions` / `kf_espn_apply_link` (handlers in `kerry-football-admin.php`, UI at the foot of `kf-week-setup.php`). These `UPDATE` the matchup row in place — no delete, no insert, no id change — so they are safe mid-week. Applying a link also links the sibling `is_tiebreaker = 1` row, or the tiebreaker never auto-populates.
  Manage Weeks only shows an **Edit** link for `draft` weeks, so a published week has no route to Week Setup — the linker is therefore rendered on **Enter Results** too, via the shared `kf_render_espn_linker()` in `kf-sports-api.php`.
- ⚠️ **Anything wired to `#matchups-container` must not write into it unguarded.** `startWeekProfile()` in `kf-game-browser.js` watches that container with a `MutationObserver` (`childList` + `subtree`), and its callback runs `kfRenderMatchControls()`, which *writes inside the same container* — it appends a `.kf-espn-link-row` per fieldset and rewrites its `innerHTML`. Unguarded, the observer re-entered its own callback forever and froze the browser's main thread: no console error, no reload, every button on the page dead including the plain-submit Save. Blank fieldsets settled (setting `innerHTML` to `''` on an empty node emits no record), so a fresh week loaded fine and only locked up once a matchup had both team names — i.e. the moment a game was added. Fixed in 1.8.3 by disconnecting the observer around the render and reattaching after, plus a re-entrancy flag for the delegated `input` handler. Keep both if you touch `refreshAll()`. The observer in `kf-player-picks.php` is safe for a different reason — `boot()` only writes classes, `disabled` and `dataset`, which a `childList`-only config ignores.
  Related: `syncMatchups()` in `kf-week-setup.php` trims by removing the last `.matchup-fieldset`, never `lastChild` — the PHP-rendered markup has whitespace text nodes between fieldsets — and stops at the first filled one, so lowering "Games This Week" can't silently discard a game.
- **Picks CSV export** (Standard section of `kf-player-picks.php`; not BPOW, not finalized weeks). `_kf_picks_export_fields()` renders every column except `pick` as JSON beside the Download/Copy buttons; the browser reads `pick` live from the `picks[<id>]` select, so an unsaved Auto-fill pass is exported. ⚠️ **The column order and formatting are a contract with an outside consumer** — do not reorder, rename, re-case or "tidy" them. Favourite and line both come from `spread_home`'s sign, the line always from the favourite's side (`-7.0`, never `+7.0`). An **unposted** line exports empty; `0` means a **posted** pick'em — conflating them invents a line. `odds_source`/`odds_as_of` appear only when a spread or total exists, since linking to ESPN stamps `odds_updated_at` even with no odds. The tiebreaker is its own extra row (N games → N+1 rows) and its `pick` mirrors its twin's select, matched on `team_a`/`team_b`. Validation blocks the export outright rather than emitting a bad file. **Copy for Excel** puts the same rows on the clipboard tab-separated with CRLF, because Excel's paste splits on tabs and never on commas; a field starting with `"` (or holding a tab or line break) is wrapped with its quotes doubled, since Excel's paste parser otherwise strips a leading quote. Verified in Excel 16: only **Data → From Text/CSV** keeps `=`/`-`-leading text as text — double-click, the legacy wizard, paste and Text to Columns all run it, and a leading `'` is kept literally on paste, so there is no in-band defuse. Double-clicking the BOM-less CSV garbles accents (`San José State`, a real team). Text to Columns is sticky: after a comma split, later pastes in that Excel session split on commas instead of tabs.
  ⚠️ `kf-player-picks.php` is **CRLF throughout**. Anchors written with `\n` never match it and LF insertions leave it mixed — patch it with EOL-aware tooling.
- ⚠️ The week summary's **Hide points** control rewrites the player headers' `colspan` (2 with points shown, 1 without). It must read the original span from `data-kf-colspan`, captured on first run — selecting `th[colspan="2"]` (as it did until 1.8.10) stops matching after the first switch, so unticking never restores the header and the names drift left across the row while the data columns stay full width. The state is remembered in `localStorage`, so a page can load already in that state.
- **Point-value dropdowns** on the picks page mark a value already used by another game in the same section "(used)" and red; they deliberately do **not** disable it (1.8.9). Disabling made every swap two steps and a greyed option is easy to miss. Duplicates are refused on submit by `initSubmitGuard`, and both duplicate rows go red. Phone pickers ignore option colours, so the text label is the part that must not be removed. The label is rewritten only when it changes, because the page's body `MutationObserver` re-runs `boot()` on every childList change.
- **Pick Compare** on the week summary (`kf-compare-bar` markup in `kf-week-summary-view.php`, behaviour appended to `assets/js/kf-table-controls.js`) highlights disagreements vs one player or vs the field. It reads `data-kf-pick`/`data-kf-player` on the pick cells and writes only CSS classes. ⚠️ It must use **outline and opacity only** — the table already encodes win/loss/tie/live in background colour, so a second background layer destroys that reading. BPOW columns and the tiebreaker row are excluded (a second pick set, and a number rather than a side).
- The week summary also carries **view controls** (hide points / game detail / compact, persisted in `localStorage`) and a **score change notice**: `kf_week_state` returns a fingerprint of the week's results which `kf-table-controls.js` polls once a minute while the tab is visible. It offers a Reload rather than re-rendering — win/loss tinting, live subtotals, the compare overlay and the scenario tool all derive from the same results, so a partial patch makes the page disagree with itself.
- `kf-week-summary-view.php` (68 KB) and `kf-player-picks.php` (45 KB) are the two heavyweight files; both mix `$_POST` handling, business logic, and HTML.
- The repo lives under OneDrive, so `git status` can be very slow and file-watching is unreliable.
- No test suite and no linter config. Verify changes by loading the affected page on a WordPress install with the plugin active.

## Working on this

There is no build or deploy script. Changes are the PHP/CSS/JS files themselves; the plugin folder is what gets installed. When making a change:

1. Bump the `Version:` header in `kerry-football-admin.php` if the change ships.
2. If the schema changed, add the `kf_maybe_upgrade_db()` block — a live site never re-runs activation.
3. Check whether the feature needs to respect a co-commissioner, not just an admin.
4. Confirm the scoring path still handles ties, BPOW second-sets, and Double Down reversal — those three interact and are the source of most past bugs.
