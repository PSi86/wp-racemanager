# Test suites

Two runners, and they answer different questions.

**`php tests/run.php`** is the one to reach for: plain PHP, no framework, no WordPress
installation required, so it runs anywhere and it is fast.

**`npm run test:e2e`**, **`test:pilot-selector`**, **`test:live-resume`**,
**`test:update-status`**, **`test:flaky-network`**, **`test:race-parts`**, **`test:stats-filter`**,
**`test:offline`**, **`test:view-tabs`** and **`test:bracket-titles`**
use a real browser, because some behaviour is what the DOM, the network, the service worker and the
browser's own storage do rather than what the source says. They are deliberately kept out
of the PHP runner — see [Browser checks](#browser-checks) at the end, which also has
**`test:class-templates`**, a check of JavaScript data that needs no browser.

```
php tests/run.php            # everything
php tests/run.php live       # only suites matching "live"
php tests/run.php -v         # print each suite's full output
php tests/suites/vapid.php   # a single suite
```

Exit code is 0 when every suite passed or skipped for a documented reason, 1 when something
failed. A single suite exits 0 (passed), 1 (failed) or 2 (skipped).

## What is covered

| Suite | Guards against |
|---|---|
| `live-routing` | The `/live/{race}/{view}/` rewrite rule, above all what it must **not** match — `/live/page/2/` (the race list's own pagination), `/live/{race}/feed/`, `/live/bracket/`. Plus URL building, race resolution, draft visibility, and that the legacy `?race_id` redirect stays inside the live area so `/register/?race_id=` keeps working. |
| `live-links` | Rewriting the live navigation so every item carries the current race, run against the **real** `WP_HTML_Tag_Processor`. Includes the full "visitor on race 66" scenario and the cases that must stay untouched. Plus the `rm-live-nav` marking of a navigation with view links — with its links already rewritten, which is how the navigation block meets them — and that the filter is registered for both of its arguments. |
| `live-shortcodes` | The four live-page shortcodes against the **verbatim WordPress 7.1 signatures** of the script module API. This is the regression guard for the 6.9 breakage: `wp_register_script_module()` gained a fifth `array $args` parameter, and anything else there is an uncaught `TypeError` that kills the whole page. Also the view tabs every view emits: one row per page, a tab per view in page order, the current one marked, none without a race, one on a finished race's next-up view, and a routing cache from before the tabs rebuilt with the page titles. And the loader told where a race's index and parts are (1.8.0): the index beside the whole file, a part named as the writer names it, `%s` for its path. And `[rm_race_log]` (1.8.1): a live race's log, and nothing for an archived race, whatever the database still holds. |
| `asset-versions` | That every asset the plugin enqueues carries `WP_RACEMANAGER_VERSION`. Read from the source with the tokenizer rather than from a rendered page, so it reaches the call sites no other suite executes — the admin, the navigation, the service worker registration — and a new file is covered the day it is added. Bundled libraries under `assets/` are versioned by the release in their directory name; the legacy `[rm_viewer]` shortcode is exempt by name, and the suite fails once that exemption has nothing left to cover. |
| `pwa-files` | `manifest.json` and `pwa-sw.js` as the plugin writes them into the WordPress root: every placeholder a template uses has a value, the worker's cache is named for the plugin version and the template, and a template changed **without** a version bump is written out all the same — it used to stay on disk as it was, because the signature that decides about rewriting did not cover the templates. Works on a copy of `templates/` so it can change one. |
| `vapid` | Key generation, the refusal to generate while subscriptions exist, key and contact validation, and constants beating the database. Runs against the real `minishlink/web-push`. |
| `registration-email` | The address the registration confirmation uses: derived from the site's own domain by default, overridable in the settings, and carried into a form the plugin created — but never over an edit an organiser made by hand. |
| `seo-head` | That `<head>` carries exactly one `<title>` — the SEO handler used to echo its own next to core's — that the per-post override reaches it through `pre_get_document_title`, and that an archive or 404 produces no undefined-variable warnings. |
| `settings-vapid` | That a plain settings save can never wipe the stored private key — it is not rendered into the form, so nothing in the request carries it. |
| `race-files` | The per-race JSON directory being created on demand and reporting failure, and the SQL scoping that keeps a bulk delete inside one race. |
| `race-status` | A race's two states (1.9.0, [`docs/race-status.md`](../docs/race-status.md)), with `update_post_meta()` modelled on core's hook order. The page cache emptied on a change of state — created live, set to archive, set live again — and on a race's first results, and **not** on a repeat: Quick Edit's `0` for an archived race, which WordPress takes for a change, the meta box's same value, a race the admin creates as archived, a post that is no race. The dot on the live link asks for a live race with results and for no time window, and the race list's *Live:* likewise. An archived race's push subscriptions deleted, and a deleted race's; the one-time archiving run again on a site that ran 1.8.1's, schema 2, and not again after. Archiving by itself: scheduled hourly on `init`, once; a table of what is due — end and last upload a day past, a second day of uploads keeping it, no end or an unreadable one keeping it, an end in the input's form on the deadline's own day read as it is meant; a run archiving through the flag, so the hooks follow; the deactivation hook; and `RM_AUTO_ARCHIVE` false taking the schedule away and a left-over run doing nothing. Three mutations of the rule each fail a check. |
| `race-writes` | What an upload leaves in `uploads/races/`. The timestamp is written last, so that a data file that cannot be written leaves it announcing what is there — it was written first, and a browser then held the old standing under the new timestamp until the next upload. Every file replaced whole rather than rewritten in place, told by its inode, and nothing half-written left behind when a write fails, with the log naming the file. Against the writer before, the timestamp check and both inode checks fail. And the parts (L7, 1.8.0): a part per top-level key, per result heat and per class, each with the hash the index names; put back together they are the payload, checked with `===` on a synthetic event and on every real payload the local site has; an update changes the hash of what changed and of nothing else, and a notification only the race log's; a list, an empty object or a key that cannot name a file keeps its object whole; parts that are gone are removed, another race's left alone; and a part or an index that cannot be written leaves index and timestamp as they were. A race deleted for good takes its index and parts with it — hooked as the file is loaded, so from the admin and the REST API alike — and leaves the whole file and the timestamp to its attachments; a post that is no race keeps its files. And an archived race (1.8.1): written without parts, index or race log whatever the database holds; set to archive, it keeps its results and the whole file and timestamp only, with a new time, and its log leaves the database — after the files, so a write that fails keeps it; stored again with nothing to clear, nothing is written (Quick Edit's number); live again, the next upload brings the parts back; not hooked to `deleted_post_meta`; a live race is not archived; and the races archived before are cleared once, not in an AJAX request. Five mutations each fail a check. |
| `activation` | What the activation hook leaves behind, and what a *second* activation must not: the `CREATE TABLE` statement `dbDelta()` can actually parse, with the subscriptions' `pilot_key`, the CF7 example form being created once rather than once per activation, and the plugin header — one version number in three places, plus the `Requires` headers. And that an update brings the table up to date without the hook, which a ZIP replace does not run: on `plugins_loaded`, `dbDelta()` once, the schema recorded only once the column is there, and not again after; and a subscription stored with its pilot key, or none. |
| `subscriptions-by-key` | Which pilot a push subscription follows once the upload names its pilots by key (the connector's `pilot_key`). Re-created pilots under new IDs: no push about the heat of whoever has the subscription's old ID now, the new ID stored, and the push when the pilot moves naming the right heat. A pilot the upload no longer has: told of leaving the heat, not of the other person's channel. A subscription from before keys: found by ID and given the key. An upload without keys, from an older connector: by ID as before, nothing rewritten. A key in capitals is the same key. And `rm_pilot_keys_by_id()`: by pilot ID, lower-cased, only valid keys. Against 1.6.1's handler the key cases fail, and each of six broken variants of the change fails it. |
| `pilot-key` | The pilot key: a version-5 UUID computed as RFC 9562 defines it (checked against Python's documented `uuid5` value), one key per address whatever its case or blanks and the same on every call, derived in the site's own namespace — created once, another one giving other keys — and `RM_PILOT_NAMESPACE` winning, an invalid one handing out no key rather than falling back (in a PHP process of its own, since a constant cannot be undefined). And that every row of `get-pilots` carries the key, checked on the endpoint itself; against the old endpoint that check fails. And that `get-pilots` carries no address, phone number or consent flag, while the admin list keeps them, and that a field the admin list gains later stays off the timer (D1 in the connector's roadmap). |
| `race-winner` | Who won a race (1.12.0). The final of every plan RotorHazard ships (`tests/fixtures/brackets/plans.json`): a single elimination's Final and not its Small Final, which no heat seeds from either, in whatever order the timer lists them; the double eliminations' Final, MultiGP's "Race 14: Winners Bracket Final", a ladder's A Main; none for a ranked fill or a class whose heats do not seed each other. The winner: the first of the final's result with the pilot key the timer sent, nobody before the final has a result or when its first has no place; a class ranked with "Brackets" by its place 1, whatever the final says; Chase the Ace undecided or without a ranking, nobody; switched off, the final decides; one winner per bracket class, in the timer's order. Kept in `_race_winner`, the page cache emptied when it changes and not otherwise; the races stored before worked out from their data files, a batch per admin request, then no more. The block: callsign escaped, photo with its version over the initials, flag, the class only when there are several, nothing without a winner or on a post that is no race. On the real events of the local site, one winner each, the first of the final. Four mutations of the rules each fail a check. |
| `pilot-profiles` | A pilot's nationality and photo (1.11.0). The countries: a code of the list in upper case, Kosovo's XK among them, anything else none; every code with its flag under `assets/` and every flag with its code, the license beside them; the names in the site's language where intl is there ("Österreich", sorted among the O's). What a registration gives: with the consent `acceptance-media` its country, which a registration without one keeps and one repeating the profile leaves undated; a code not on the list changes nothing, a file that is no image is no photo; no address or nothing to keep stores nothing; the option is not autoloaded; without the consent the profile goes, photo and option with it. Deleting registrations: only the race's own, and the profile of a pilot with none left, not of one still registered for another race. A photo's metadata: EXIF, IPTC and comments go, GD's own included, the colour profile and Adobe's transform stay, the image data untouched and still an image of its size; no JPEG and a JPEG cut short are refused. What a race's files carry: its own pilots' profiles by key, a photo as its URL with version, nothing for a version that is none or a race without keys. Three of four mutations of the rules fail a check; the fourth is equivalent. The photo itself - upright, cut, without metadata, with GD and with Imagick - is `tests/e2e/pilot-profiles.cjs`. |
| `rest-auth` | That the RotorHazard endpoints ask for a capability instead of just "is logged in", that every route and method is behind that gate — a route that answers GET and POST counted twice — that the upload's optional `race_id` is checked per race, and that the dead API key check stays gone. |
| `race-selection` | A timer naming its race: the upload with `race_id` updates exactly that race and never creates one — the lookup by title that D3 in the RotorHazard plugin's roadmap is about does not even run — while an upload without it still goes by title, for older timers. `GET /races` lists the 15 newest races the user may edit — counted after that check, read page by page past the ones they may not, the newer post first among equal starts; `POST /races` creates a race from the event, with its files from the start, and refuses a title another race has — the title as it would be stored, a race in the bin not counting — naming that race and saying whether the user may edit it. And the HTTP status of each refusal: 404 for no race, 403 without the right, 400 for a locked race, 409 for a title that is taken, 500 when the files cannot be written. A saved upload stays a success when working out who flies next fails or the push library throws, and says that nobody was notified (D8 in the connector's roadmap); a notification without a click URL links to its race's live page; its icon is one of the names `lunch`, `break` and `warning` for the images this plugin ships, a web address as it is, and the site's app icon for anything else (D6 there). And (1.8.1) a message to an archived race refused with 400 and its reason, to an ID that is no race or a race in the bin with 404, nothing logged or pushed either way; and a race created from an event live before its first files, so it has its parts from the first upload. Against the handler before, those seven checks fail. |
| `compressed-bodies` | A timer's gzip-compressed body on the `rm/v1` routes: decoded on `rest_pre_dispatch`, before core would refuse it as invalid JSON, whatever the header's case, `x-gzip` too, and a 1.9 MB event as well as a small one; left alone without the header, for `identity`, on another namespace and when another filter has answered; refused with 400 when it is no gzip or cut short, with 415 for another encoding, and with 400 when it inflates past 10 MB — a 19 kB body that inflates to 20 MB stops there, the memory it took measured, because `gzdecode()`'s own limit does not hold; not inflated at all for a stranger, who gets the gate's 401 or 403 first. And that every answer of the namespace, a 415 included, carries `Accept-Encoding: gzip`, and no other does. And that a PHP without zlib does not say so and answers a compressed body 415 instead of a fatal error — in a PHP process of its own with `inflate_init()` disabled, which PHP 8 treats as not there; against 1.6.0 as merged that run died of `Call to undefined function inflate_init()`. |
| `page-cache` | Every `rm/v1` request marked as not to be cached before any callback runs — `DONOTCACHEPAGE`, and LiteSpeed Cache's `litespeed_control_set_nocache` with a reason — and every answer, a refusal included, with `X-LiteSpeed-Cache-Control: no-cache`; another namespace left alone. What LiteSpeed Cache 7.9.1 makes of it was measured on the local site with the plugin active and LiteSpeed emulated: before, `public,max-age=604800` for a timer's `GET /races` and `GET /get-pilots`; after, `no-cache`, and the same with the constant alone or the call alone, while the header alone was overwritten with `public`. |
| `nextup-schedule` | Who flies next, as every upload works it out for the "your next race" pushes (`rm_getUpcomingRacePilots()`). The heat on the timer is announced when it is the last one, and when the timer numbered its heats from 50 — the loop's guard counted from the heat's id and gave up at once in both cases, so no next-up push went out for a final. A slot the timer fills from a class's result (method 2) takes that class's pilot, from its ranking when it has one, not the pilot of the heat that carries the class's number; a slot filled from a heat's result takes the entry at `seed_rank - 1`, as RotorHazard seeds. Against 1.9.0 all five checks fail; with the guard fixed alone, the three seed checks still do, each for its own reason. And a Chase the Ace final (1.10.0) - the class ranked with "Brackets", its switch untouched - stays the heat to come until the timer's ranking has its place 1, whatever the class's number of rounds says; decided, or switched off, it is done after its round. Against 1.9.1 the undecided case fails. |
| `push-delivery` | Pushes after the answer: `rm_after_response()` hooks shutdown once, late, closes the connection before the first task — PHP-FPM's `fastcgi_finish_request()` here, LiteSpeed's `litespeed_finish_request()` and neither in a PHP process of their own — runs the tasks in order and each once, logs one that throws and goes on, and says in `debug.log` who closed the connection. The next-up pushes and a message to all followers are queued inside the request, with the followers' heat and slot stored there, and sent only after the answer: all at once with the asynchronous client, one after another without; a subscription its push service calls gone is forgotten, another failure logged. A new subscriber's confirmation still goes out at once. And, with the real library, the client the handler builds: 50 pushes at a time, 10 s each and 5 s to connect, on the asynchronous client as well. |

## Optional dependencies

Two suites need something extra and **skip themselves cleanly** when it is missing — a skip
is not a failure, but it does mean that area is unverified on this machine.

**`live-links` needs a WordPress checkout** for the HTML API. It is looked for in this order:

1. `$WP_CORE_DIR`
2. the WordPress root three levels above the plugin — so if the plugin sits in
   `wp-content/plugins/wp-racemanager/` of a development install, this needs no setup at all
3. `tests/.wordpress/` (git-ignored), if you drop a checkout there

```bash
WP_CORE_DIR=/path/to/wordpress php tests/run.php
```

**`vapid` needs the push library**:

```bash
composer install
```

That creates a plugin-local `vendor/` (git-ignored). The plugin finds the autoloader there or
in the locations it has historically lived — see `rm_push_library_available()`.

## Browser checks

```bash
npm run test:pilot-selector                         # no WordPress needed, only a browser
npm run test:live-resume                            # against https://racemanager.ddev.site
npm run test:update-status                          # against https://racemanager.ddev.site
npm run test:flaky-network                          # against https://racemanager.ddev.site
npm run test:race-parts                             # against https://racemanager.ddev.site, and ddev
npm run test:stats-filter                           # against https://racemanager.ddev.site
npm run test:offline                                # against https://racemanager.ddev.site
npm run test:view-tabs                              # against https://racemanager.ddev.site
npm run test:bracket-titles                         # against https://racemanager.ddev.site
npm run test:class-templates                        # no WordPress and no browser, only Node
npm run test:push-subscribe                         # no WordPress needed, only a browser
npm run test:bracket-view                           # no WordPress needed, only a browser
npm run test:bracket-model                          # no WordPress and no browser, only Node
npm run test:bracket-standings                      # no WordPress and no browser, only Node
npm run test:loader-subscribe                       # no WordPress needed, only a browser
npm run test:stats-ranking                          # no WordPress needed, only a browser
npm run test:pilot-profiles                         # against https://racemanager.ddev.site, and ddev
npm run test:e2e                                    # against https://racemanager.ddev.site
RM_E2E_URL=https://other.ddev.site npm run test:e2e
RM_E2E_SHOT=shot.png npm run test:e2e               # also save a screenshot
```

All of them but `test:class-templates`, `test:bracket-model` and `test:bracket-standings` run
through Playwright, and all of those skip rather than fail when Playwright or its Chromium is
missing.

Three of the files under test have a copy on the timer: `rm-m-pilotSelector.js`,
`rm-m-displayHeats.js` and, since 1.10.0, `rm-m-bracketModel.js` serve the RotorHazard connector's
`/bracketview` too, on a `dataLoader` of its own that reads RotorHazard's socket. Since
2026-09-12 this repository is their source, and the connector takes them over byte for byte, so a
change here reaches the timer with its next release. What the timer needs of them is checked here
as well. `class_templates_V1.js` was the third before 1.10.0; it now serves only the legacy
`[rm_viewer]`.

### `tests/e2e/pilot-selector.cjs`

`js/rm-m-pilotSelector.js` against the real module in a real DOM. It needs **no** WordPress and no
DDEV: the page is assembled in the test and the `dataLoader` the module imports is served as a
stub, so the subscriber callback can be driven directly. A browser is required all the same,
because what is under test is what a `<select>` does with its options and its `selectedIndex` —
exactly what a hand-written DOM stub tends to get wrong.

It covers both halves of **D1**, which have to hold together: that repeated data updates rebuild
the option list instead of appending another copy of it, and that a pilot leaving the field mid-race
falls back to the placeholder instead of leaving the control blank at `selectedIndex === -1`. Plus
that the server-rendered placeholder survives the rebuild, that the fallback dispatches a `change`
so `displayHeats` and `displayStats` stop filtering by a pilot the list no longer offers, and that a
page without the control does not take the module down on import.

Run against the module as it was before the fix, five of its eight checks fail — which is the
point of it.

And it covers the timer: its `dataLoader` hands a new subscriber an empty object until every
section has come in over the socket, and the module waits that out instead of throwing. Against the
module before that, the check fails with *Cannot read properties of undefined (reading 'pilots')*:
on the timer, that stops the whole bracket page.

And the pilot key (1.7.0): every option carries it, and when the timer re-creates its pilots under
new IDs the selected pilot stays selected under the new one, announced with a `change` so the other
modules filter by it; a pilot gone, whose last ID someone else has now, falls back to the
placeholder rather than to that person. Against the module before, three of these fail — the
selection moved to whoever had the old ID.

### `tests/e2e/push-subscribe.cjs`

`js/rm-m-pwa-subscribe.js`: which pilot a push subscription is for. No WordPress and no push
service: the pilot selector it imports is a stub, and the service worker, its push manager and
`admin-ajax.php` are stood in for, so what the module sends and what its button offers can be read
directly.

Subscribed to a pilot who then got a new ID: selecting them offers *Unsubscribe*, selecting the one
who has their old ID offers *Update Subscription*, and moving the subscription sends the new
pilot's key with the ID and callsign. Without keys, by ID as before, and an empty key sent. Against
the module before, four of its six checks fail: the button offered *Unsubscribe* for whoever had
the old ID, and pressing it would have ended the subscription instead of moving it.

### `tests/e2e/class-templates.cjs`

`js/class_templates_V1.js`, the bracket templates `rm-m-displayHeats.js` laid an elimination
class out on until 1.10.0, and the legacy `[rm_viewer]` (`bracketV25.js`) still does. Plain data,
evaluated in Node; no browser.

Each race of a template carries seeding labels, a seed position (`16th`) or a result
(`2nd race 1`), which the heats' slots overwrite one by one; an entry beyond a heat's slots stays.
Two races of the 32-pilot template carried *TestPilot1* to *4* instead, both under the IDs 49–52.
On the live pages a race with three slots showed "TestPilot4"; on the timer, whose copy had the
names blanked, the leftover still counted as pilot 49 to 52 when filtering by a pilot and on hover.
The check: every entry a seeding label, every entry ID and race ID used once. Against the template
before, both fail.

### `tests/e2e/bracket-view.cjs`

`js/rm-m-displayHeats.js` and `js/rm-m-displayStandings.js`, the bracket view, against the real
modules in a real DOM, with the `dataLoader` and the pilot selector served as stubs,
`rm-m-bracketModel.js` as it is, and every race built from the upload's shapes - RotorHazard's own
heat plans among them (`tests/fixtures/brackets`, see its README), no real names. No WordPress, no
DDEV.

Every class of the event gets a section, whatever its name (1.10.0), the newest on top: the timer's
order turned round, by `order` or else by id, and the standings under them likewise (1.12.1; 1.10.0
drew them in the timer's order, which put training on top where the page's fixed containers had had
the elimination - the three checks of that failed against 1.12.0). A class
whose heats form a bracket is drawn as that bracket: "Winners Bracket" and "Losers Bracket" titles, a
header per round ("Quarterfinals", "LB Round 3", "Grand Final"), a line per link in the path shape
`bracket-titles.cjs` measures, the grand final marked; a single elimination in one section with its
small final below the final; the others as a row. A Chase the Ace final names the rule and the
rounds flown and shows each pilot's wins, the winner marked. The pilot filter keeps the pilot's heats
and the heats they feed. The standing under the brackets: ranges while a round runs, the final four
of an undecided Chase the Ace without places but with their wins, no result column when nothing
stands in it, no standing for a class that forms no bracket. Nothing throws - not on `{}`, not
without results, not for an FAI 32 bracket flown by 12 pilots, not for a class the view cannot read,
whose neighbours are still drawn. Seeds are labelled and resolved as RotorHazard seeds, and results
show whenever a heat has them. A page with the old fixed containers still works. And the pilots'
flags and photos (1.11.0), from the race's `pilot_profiles` by pilot key whatever its case: no flag
where the page does not say where the flags are, which is the timer's case; with it, the flag by the
callsign in every heat, none for a pilot without a country or with one that is no code; in the
standing the photo, the initials for a pilot without one and for a photo that fails to load, no photo
from an address that is no web address; nothing at all for PHP's empty `[]`.

Against 1.9.0, ten of the 1.9.1 checks failed (a throw at the 15th heat of an FAI 32 bracket in an
event of 12 pilots, and every class after it empty). Against 1.9.1, every check of the sections,
brackets and round names fails, and the run stops at the Chase the Ace node, which 1.9.1 does not
draw.

### `tests/e2e/pilot-profiles.cjs`

A pilot's nationality and photo (1.11.0), sent through the registration form on the development site
from a real browser: Contact Form 7 deletes its uploads once a submission is done, so only a real
submission shows that the photo is taken in time. `tests/e2e/pilot-profiles-site.php`, through
`ddev wp eval-file`, makes a race open for registration and a page with the example form, hands over
a photo as a phone stores it - 600 x 400, red left and blue right, EXIF orientation 6 and an Artist
tag - reports what the plugin made of it, and removes it all again, also after a run that did not
finish.

The form offers the countries and a photo field and is sent; the registration is stored with the
country's code; the profile has the country and the photo; the photo is a square JPEG of 256 pixels,
red on top and blue at the bottom - turned the way the phone meant it - with neither the EXIF block
nor the Artist left; the photos' directory lists nothing; a race's files carry country and photo URL
with the photo's version; deleting the registration takes profile and photo away. The same photo then
goes through each image editor the site has, GD and Imagick. The first run failed the metadata check:
WordPress's Imagick keeps EXIF, IPTC and XMP on purpose, and only the plugin's own filter of the JPEG's
segments takes them off (`rm_jpeg_without_metadata()`).

### `tests/e2e/bracket-model.cjs`

`js/rm-m-bracketModel.js` in Node: the bracket a class's heats form, worked out from their seeding.
Every regulation bracket RotorHazard 4.4.0 ships - FAI 16, 32 and 64 single and double elimination,
MultiGP 16 - comes out as the bracket it is: groups (winners, losers, grand final, small final),
single or double, the final where it is, round names as DRSK names them for FAI 32 (Round 1,
Quarterfinals, Semifinals, Winners Final, LB Round 1-6, Grand Final). Ladders and ranked fills are
no bracket. The layout puts no two heats on one grid position and runs every line left to right.
A pilot put into a later heat by hand, a seed from another class and a generator's record that does
not match the heats are reported; heats seeding each other in a circle and a generator run twice
into one class give a row; nothing throws. Seeds resolve by index; the rulebook comes from the
generator's record. Chase the Ace: off without the "Brackets" ranking method or with its switch off,
decided by the timer's ranking or else by two round wins; a round flown after the deciding one
counts neither as a round nor as a win, as in Class Rank: Brackets (failed against 1.10.0, which
showed "3/2"; found on the RotorHazard 4.4.0 bench with that plugin). And the three real events of the local
site, when there: FAI 32 double elimination each, laid out without overlap.

### `tests/e2e/bracket-standings.cjs`

`js/rm-m-bracketStandings.js` in Node: a bracket's standing. A flown FAI 32 double elimination gives
places 1 to 32 in the rulebook's ranges - 1-4 Grand Final, 5-6 LB Round 6, 7-8 LB Round 5, 9-12,
13-16, 17-24, 25-32 - and one that is not full (22 or 12 pilots) places 1 to N, heats that can hold
nobody counting as done; a single elimination's small final is 5-8. While a round runs, those out
share its range, counted from the bottom; nobody above is placed yet. A timer with more nodes than
the heats seat: the empty slots are no places. Inside a round of several heats FAI orders by
qualifying rank, MultiGP by the rank in the heat first. Chase the Ace: undecided without places,
two wins then points, or the timer's ranking as it is. Points count up to the round that decided,
which also breaks ties, as in Class Rank: Brackets; a round flown after it changes nothing (failed
against 1.10.0, which counted it and reordered places 2-4). A hand-edited bracket gets a notice. On the
real events of the local site the places 5 to N equal those of the ranking before 1.10.0
(`tests/fixtures/brackets/old-ranking.cjs`), except two pilots without a qualifying result, who
share a place the old ranking split by heat order. Three cases failed against the first version of
the module and are fixed: a bracket of 12 pilots placed nobody (its empty heats counted as still to
come), the real events ordered by the seed rank instead of the qualifying standing, and an 8-node
timer's empty slots widened a running round's range to 1-24.

### `tests/e2e/loader-subscribe.cjs`

`js/rm-m-dataLoader.js`: a subscriber that throws. A returning visitor has the race in
`localStorage`, so `subscribe()` hands it over at once, inside the subscribing module's start-up; a
throw there escaped `subscribe()` and ended that start-up half done. The check: `subscribe()` does
not throw, and the next subscriber still gets the cached data. The page and every URL the loader
asks for are answered by the test. Against the loader before (1.9.0), the first check fails.

### `tests/e2e/stats-ranking.cjs`

`js/rm-m-displayStats.js`: the class ranking panel, with `buildRanking()` called on the module's own
instance. A class has a ranking once the timer ranks it with a ranking method — "Class Rank:
Brackets" for a Chase the Ace final — and the panel called a translation function that was never
imported. The checks: a ranking is a table with the method's own columns and the result the timer
gave; a method that ranked nobody (`{}` and `{}`) and a ranking without meta each say so in a
sentence. Against the module before (1.9.0), all seven fail with *__ is not defined*.

### `tests/e2e/live-resume.cjs`

`js/rm-live-resume.js`, and what the selection page does with the race it remembers. Needs a
started site with **at least two races that carry result data**, because the whole question is what
happens when a visitor comes back; it skips when there are fewer.

The behaviour is a state machine split between the server and `localStorage`, and the two halves
disagreeing is what it guards. The selection page resolves a race of its own — the header link back
to it carries `?rm_race=<slug>` — so "the server gave me a race" is a different question from "this
is a race page". It covers: a race page storing itself; the selection page reached without the
marker still offering the stored race *and* marking it in the list; the selection page reached
*with* the marker marking that race and not also offering it; the marker keeping the stored entry
in step; and `?resume=1` going straight through.

And the state of a race (1.9.0), asked of the page's own list of live races: *Live:* on exactly
those; `?resume=1` straight on into a live race; and for one that is over, staying on the selection
page, where it is still offered — the app installed at one event used to open the next one in the
race long over. Needs a live race and an archived one for the last three, and says so otherwise.
With the list and the script from before and the new configuration, those three fail.

### `tests/e2e/update-status.cjs`

`js/rm-m-dataLoader.js` and `js/rm-m-updateStatus.js` — where the cache goes, how the polling
loop schedules itself, and what the freshness pill is willing to claim. Needs a started site with
a race that has result data; the polling half additionally needs that race flagged live
(`ddev wp post meta update <id> _race_live 1`), and says so rather than failing when it is not.

Its four groups are different kinds of claim, and the difference is the point:

- **The cache** is checked by looking at `localStorage` directly: the payload under its prefixed
  keys — whole, or in parts for a race with an index (1.8.0) — the metadata beside it, nothing left
  in `sessionStorage`, another race evicted on demand with its parts, and so is a race whose ID
  begins with this one's — and `rm_last_race`, which belongs to `js/rm-live-resume.js`, still there
  afterwards. That last one guards a prefix that is one careless character away from sweeping up
  the resume entry.
- **The scheduling** is exercised by calling `scheduleNext()` and `currentDelay()` directly rather
  than by waiting out real intervals: twelve delays all within ±20 % and not all equal, the
  backoff doubling to its cap, and a hidden page scheduling nothing at all.
- **The state machine** runs against the exported, pure `describe()` over a table of states. That
  is why it is exported: offline, a failing check, unconfirmed data and an overdue check are all
  states that need a broken network to happen naturally, and every one of them has to fail to say
  "up to date". The same table covers when the pill appears at all — on a race that is not live it
  stays hidden, including while it loads and including when the browser goes offline *after* a
  successful check, and comes back only when the data could not be loaded.
- **Two things a pure function cannot catch**, both found by looking at a browser rather than at a
  result line, and both now guarded here: that the `hidden` attribute actually hides the element
  (the stylesheet sets `display`, which beats the browser's own `[hidden]` rule), and that a load
  which never succeeds settles on the failure instead of sitting on "Loading race data…" for good.
- **The saving** is measured in bytes off the wire, with a **persistent browser profile**. A fresh
  Playwright context is a first-ever visit and would prove nothing; only a profile that survives a
  browser restart reproduces what a returning viewer does. First visit ~100 KB, coming back ~111
  bytes.

Run against the loader as it was before the change, the returning-visitor checks fail: it
downloaded the full payload every time, 100,839 bytes.

### `tests/e2e/flaky-network.cjs`

The live app on a bad mobile link, and the only suite here written in response to something that
happened at a real event rather than to something found while reading code.

The report: for some spectators the app came up empty and **stayed** empty. Reloading did not
help, reloading again did not help, and it came back only when the app was killed outright.

The cause was an ordering mistake in the loader. It recorded the timestamp it had just fetched
*before* downloading the payload that timestamp pointed at. When the download failed — which on a
fading connection it does — that version was already marked as seen, so every later check found
the timestamp unchanged and never asked for the data again. The note lived in `sessionStorage`,
so it survived every reload and died only with the tab.

Run against the pre-2026 loader this suite reports exactly that: `cachedTimestamp` set although
the payload never arrived, `0` payload requests on each subsequent reload, and no standing on
screen even with the network fully healthy again.

Five things are covered, and the second was found *by writing this suite* rather than from the
report:

1. A payload that never arrives leaves nothing behind that suppresses the next attempt.
2. A response whose body stalls after the headers does not wedge the loader either — a second way
   into the same dead end, and the one a fading link produces most often. The abort deadline has
   to cover the body read, not just the headers.
3. Twelve rapid taps on the refresh control produce at most two requests.
4. A slow but working link still delivers, and the payload gets a longer deadline than the 30-byte
   timestamp check so that a download about to succeed is not cut off every time.
5. With a warm cache, a total outage shows the last known standing rather than an empty page —
   the difference between "the app is broken" and "the app is behind".

Needs a race flagged live, like `update-status.cjs`. The payload it blocks is everything but the
timestamp — the whole file and, since 1.8.0, the index and the parts. Every scenario starts without a
cache, so it runs on the whole file; the parts on a bad link are `race-parts.cjs`'s.

### `tests/e2e/race-parts.cjs`

The payload in parts (L7, 1.8.0): `js/rm-m-dataLoader.js` against the files
`includes/race-files.php` writes. Measured on the three real races, an update after a heat costs
13–18 % of the whole file when only the parts that changed are downloaded; this suite checks that
the loader does exactly that, and that neither load-bearing ordering is lost on the way.

It needs `ddev` besides the site, because it writes the race's files the way an upload does:
`tests/e2e/race-parts-site.php`, run through `ddev wp eval-file`, keeps a copy of the race's
files, writes them anew with the plugin's own writer, marks a heat as flown — the heat, its class,
the event leaderboard, `current_heat` — and at the end puts every file back byte for byte, whatever
happened. A copy left by a run that died is put back first. It does nothing when requested over
HTTP. Service workers are blocked: the worker never touches race JSON, and `offline.cjs` covers it.

1. **A first visit** downloads the whole file once, no index, no part; the loader knows every part
   from `rm_index`, hands on the payload without it, and stores it in parts, a key each.
2. **An update after a heat**: the timestamp, the index, then exactly the parts that changed and not
   the whole file; the data put together from them equals the new whole file; a fraction of its
   bytes (locally 11,365 B against 102,039 B, gzip).
3. **Coming back** asks for the timestamp only and puts the payload together from storage.
4. **An upload that overtakes the index** while a part is on its way — the next upload is written
   while that request is held: the index is read again, the part is not downloaded twice.
5. **A part that does not arrive**: the update fails, and neither timestamp, index nor storage
   moves; the parts that did arrive are kept, and the next attempt downloads only the missing one.
   **A part whose body stalls** after the headers is given up at the deadline, as a failure that
   commits nothing.
6. **The whole file instead** where the parts will not do: no index, more than half changed, an
   index older than the timestamp (read three times first).
7. **A race stored before 1.8.0** costs no request for an index and is stored whole.

Against the loader from before 1.8.0, 33 of its 45 checks fail.

### `tests/e2e/offline.cjs`

The service worker's side of a bad link (L6). `flaky-network.cjs` shows that a warm
`localStorage` turns an outage into old data; this one shows there is still a page to show it on.
Before L6 the worker had no `fetch` handler, and a reload without reception got the browser's error
page — `net::ERR_INTERNET_DISCONNECTED`, which is what 14 of the 25 checks that existed at the
time reported against it.

Offline is `context.setOffline(true)`, and a link that is up but delivering nothing is
`context.route` holding every request: in Chromium that reaches the worker's own fetches as well,
which `page.route` does not. What it covers:

1. **The first visit is kept**, although the worker was installed during it and never saw the page
   load — the visit a spectator at the trackside is most likely to have had.
2. **What is kept**: the page, a versioned view module, the loader (which carries no version) and
   the pill's stylesheet — and **no race JSON**, which the loader keeps itself and must fetch from
   the network for the pill to be honest.
3. **A reload without a connection** shows the page, styled, with the standing from `localStorage`,
   and the pill does not claim it is current. And **launching the installed app** without one —
   `start_url` is the selection page, which this visitor never opened — goes on to the race last
   viewed. The first version of the worker failed exactly that: it kept every race page and not the
   page the app starts on.
4. **A page never opened here** gets a *No connection* page with status 503, not the browser's.
5. **Back online, the page comes from the network**, told apart from the kept copy by its `Date`.
6. **Housekeeping**: a cache named like an older worker's is deleted on activation, one belonging
   to something else on the origin is left alone. Both are planted before the worker first runs.
7. **A link that stops delivering** gets the kept page after the worker's 5 s deadline, and the
   page's files do not each wait out a deadline of their own: 5.0 s to a usable page, where the
   first, cache-first version took 10.05 s.
8. **A page marked `no-store`** — the header WordPress sends a logged-in user, faked on the response
   so no credentials are needed — is shown but not kept.
9. **Online, the network decides**, even for a URL with `?ver=`: a stylesheet changed without a
   version bump reaches the page, and replaces the kept copy.

The order is not free. After a failure the worker answers a page's *files* from its copies for 30 s,
so the checks that need the network to win (5 and 9) run before the one that holds every request
(7).

### `tests/e2e/view-tabs.cjs`

The live views on a phone (L9), at 390 × 844 with touch. Measured on production before the change:
every switch between views went through the theme's burger, the overlay's items were 32 px tall,
the burger 30 px, the pilot dropdown 29 px and the filter checkbox 13 px, and the current view was
marked only in the markup.

1. **The view tabs** are fixed to the foot of the screen across its full width: a tab per view, each
   at least 44 px tall and pointing at this race, exactly one current and marked by weight and a
   bar rather than colour alone, the foot of the page padded by the row's height, and the pill —
   where it shows — above the row rather than on it.
2. **One tap** on another tab opens that view, where that tab is then the current one.
3. **The pilot filter**: dropdown and checkbox label at least 44 px, and a tap on the label's text
   toggles the box.
4. **The theme's navigation**, if it has view links (`bin/bootstrap-devenv.sh` creates one; without
   it the section skips): the burger answers 20 px from its centre, the overlay's items are 44 px
   tall, the current view looks different, and the open overlay covers the tabs.
5. **A wide screen** shows no tabs and pads nothing.
6. **No race, no tabs**; a finished race's next-up view keeps them.
7. **No JavaScript**: the tabs are plain links and still there.

Against the plugin as it was, 16 of the 20 checks that run fail; the navigation section skips,
since nothing marked the navigation then.

### `tests/e2e/bracket-titles.cjs`

The bracket view scrolled sideways on a phone. Every race class is a horizontally scrolling
container, and the elimination bracket of a real race runs some 750 px past a phone's screen. The
class titles — *Elimination: Winner Bracket*, *Elimination: Looser Bracket*, *Qualifying*,
*Training* — stay where they are while the races and their connecting lines scroll under them.

For every class wider than the screen, scrolled to its end: each title is where it was, left and
top, to the pixel, and whole in view; the row it sits in holds no race; the races and the lines
moved by exactly the distance scrolled; and the title has an opaque background, because its row
does hold lines — scrolled to the end, the drop from the winner bracket into the looser final
crosses *Elimination: Looser Bracket*, and the suite reports it.

Against the bracket as it was, 8 of the 16 checks of the first version fail: all four titles
scroll away. Without the background, the 4 background checks fail. The spanning of the titles over
every column leaves the layout alone — every race box, every line and every grid size came out
identical to the old code at phone and desktop width, measured once when the change was made.

### `tests/e2e/stats-filter.cjs`

The pilot filter on the stats view: a dropdown that marks one pilot in every leaderboard they
appear in, and a checkbox that drops everyone else. The same two levels the bracket view has
always offered.

What makes it worth its own suite is how far the filter has to reach. Every leaderboard on the
page comes out of one method — class summaries, per heat, per round, event totals — and underneath
them sit the per-round lap tables, which carry the same `pilot_id`. Filtering the standings while
leaving everyone else's lap times under them is the kind of half-applied filter that makes people
stop trusting the control, so that is checked by counting both. A page of table headers with no
rows beneath them is the other failure it guards against, which is what the pruning pass exists
for; a pilot who appears nowhere gets a stated answer rather than a blank page.

The last group re-checks the **bracket** view. `displayStats` now imports `pilotSelector`, which
the bracket has always imported, and both views share one selection — so the bracket's own dimming
and structural filtering are re-measured here to make sure nothing moved.

### `tests/e2e/editor.cjs`

The block editor, through Playwright. Exit codes match the PHP suites: 0 passed,
1 failed, 2 skipped. It skips — rather than fails — when Playwright is missing, when no Chromium
has been downloaded for the installed Playwright build, when the site is unreachable, or when the
login is refused; each of those prints the command that fixes it. `RM_E2E_USER` and `RM_E2E_PASS`
default to the `admin` / `admin` that `bin/bootstrap-devenv.sh` creates.

What it guards:

| Check | Why it cannot be a PHP suite |
|---|---|
| the editor canvas is an iframe | Since 7.1 this is unconditional — there is no `apiVersion` check left in `editor`, `block-editor` or `edit-post`. A block that is not iframe-safe no longer degrades the editor, it just misbehaves. |
| every block is on `apiVersion: 3` | Read from the live registry rather than from `block.json`, so a block that fails to register is caught too. |
| each block renders inside the iframe | Insertion and rendering are editor behaviour. Blocks that declare a `parent` or `ancestor` are skipped with the reason, because they cannot be inserted at the document root — `nav-latest-races` only lives inside a `core/navigation-submenu`. |
| `race-gallery` thumbnails, its inline `<style>`, and its `wp.media` frame | The block ships its CSS as an inline `<style>` instead of through `block.json`, so what matters is that the element reaches the iframe document *and still applies* — the check measures the rendered thumbnail at 150px. The media modal is the Backbone one: the block's JavaScript runs in the parent realm, so the modal opens over the iframe rather than inside it. None of that is visible from the source. |
| the race announcement pattern, its styles, and a new race's template (1.12.0) | The pattern is markup of core blocks, and only the editor can say whether it takes them for its own: each block's saved HTML has to be what its `save()` gives, or the editor reports "unexpected or invalid content". The markup was serialized by WordPress 7.1's editor for that reason. The styles have to be offered - schedule for a table; checklist, allowed and not allowed for a list - and a new race has to start with the pattern's blocks in its Details block, the template's `core/pattern` replaced, every block valid. |
| no `apiVersion` deprecation, no console error from this plugin | The browser console is the only place these appear. |

The winner block of 1.12.0 is inserted with the others; inserted last and selected, its floating
toolbar covered the gallery's button, so the gallery check clears the selection before its click.

The media checks skip when the library holds no image; `ddev wp media import <file>` gives it one.

This is the check to repeat when a WordPress major changes the editor again. The last time it
earned its keep was the `apiVersion: 3` migration (**A2** in
[`wordpress-update-audit.md`](../docs/wordpress-update-audit.md)), where nothing but a real editor
could answer whether the Backbone media modal survives the iframe.

## Writing another suite

Drop a file in `tests/suites/`. `run.php` picks it up automatically.

```php
require_once __DIR__ . '/../bootstrap.php';

// Define any stub you need *differently* before loading the shared ones —
// everything in tests/stubs/wordpress.php is guarded with function_exists().
function get_post_meta( $id, $key, $single = false ) { return 'whatever'; }

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/the-file-under-test.php';

rm_test_section( 'What this group is about' );
rm_test_check( 'the assertion', $actual === $expected, 'shown only on failure' );

rm_test_finish();
```

Helpers from `bootstrap.php`: `rm_test_section()`, `rm_test_check()`, `rm_test_skip()`,
`rm_test_finish()`, `rm_test_wp_core_dir()`, `rm_test_load_html_api()`.
From `stubs/wordpress.php`: `rm_test_post()` for fixtures and `rm_test_redirect_from()` to
observe a redirect without the script exiting.

## A note on the stubs

Where real WordPress behaviour matters, the stubs follow core rather than a convenient
approximation — `home_url()` prepends a slash to its path, `add_query_arg()` accepts both of
its signatures. A test that assumes the wrong thing is worse than no test: during this
work a "failing" assertion turned out to be a wrong stub, not a wrong implementation.
