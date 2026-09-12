# Deployment test protocol

What to check by hand after deploying the WordPress 6.9–7.1 catch-up
([#2](https://github.com/PSi86/wp-racemanager/pull/2),
[#3](https://github.com/PSi86/wp-racemanager/pull/3),
[#4](https://github.com/PSi86/wp-racemanager/pull/4),
[#5](https://github.com/PSi86/wp-racemanager/pull/5)).

Automated coverage is in [`tests/`](../tests/README.md) — 147 checks. This list covers what
only shows on a running installation.

---

## ⚠️ Before deploying — do not skip

- [ ] **Back up the VAPID keys.** On the production install the push keys are still in
      `includes/pwa-subscription-handler.php`, and that file is overwritten by the deploy. Copy
      both values out first — otherwise **every** existing subscriber has to subscribe again,
      and it cannot be undone.

---

## 1 · Immediately after deploying

These three first — they decide whether testing can continue at all.

- [ ] **Re-save Permalinks.** Settings → Permalinks → Save, without changing anything. Writes
      the new `/live/{race}/{view}/` rules. Without this, every new URL returns 404.
- [ ] **Enable debug logging.** `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php`. At the end of
      the run, `wp-content/debug.log` must contain no new entries from the plugin.
- [ ] **A live page loads at all.** Open any race's bracket view. Expected: a complete page. No
      "There has been a critical error", and no `TypeError: wp_register_script_module()` in the
      log.

## 2 · Push notifications

The part with the only irreversible step. The decisive test is the third one.

- [ ] **Open Settings → RaceManager.** Expected: a *Push Notifications* section stating that no
      keys are stored and that **none were generated** because subscriptions exist. That is the
      intended behaviour, not a fault.
- [ ] **Import the backed-up pair.** Fill both fields, save. Status changes to *Keys are stored
      in the database* and the public key is shown. Filling only one field → error, previous
      state kept.
- [ ] **An existing subscription still works.** Trigger a notification to a device that
      subscribed **before** the deploy. If it arrives, the import took. Nothing else proves
      this — a fresh subscription would work with any key pair.
- [ ] **Complete a new subscription.** On the nextup page, pick a pilot and subscribe. Test
      notification arrives.
- [ ] **Saving settings does not clear the keys.** Change any unrelated field and save. The
      public key is unchanged afterwards. (The private key is never rendered into the form,
      which is exactly why this was a risk.)
- [ ] **`keygen.php` is gone.** `/wp-content/plugins/wp-racemanager/keygen.php` returns 404.
- [ ] *Optional:* **switch to constants.** `RM_VAPID_PUBLIC_KEY` and `RM_VAPID_PRIVATE_KEY` in
      `wp-config.php`. Status changes to *defined in wp-config.php* and the generate button
      disappears. Recommended for production — keeps the key out of database dumps.

## 3 · Live area: the new URLs

The largest change. Best tried on a copy first.

- [ ] **Selection → click a race.** URL is `/live/{race-slug}/bracket/`. **No redirect** in the
      network panel — the page is served directly.
- [ ] **The navigation points entirely at the same race.** On race 66's bracket: **every**
      navigation item leads to `/live/{slug-66}/…`. Not one link may still point at a race-less
      `/live/stats/`. Labels and styling unchanged.
- [ ] **The selection link carries the race.** The selection entry points at
      `/live/?rm_race={slug}` — **not** `?race_id=`. The race is then marked as active in the
      list.
- [ ] **Pagination of the race list.** Create more than ten races, or lower the per-page count,
      then click *Next*. `/live/page/2/` must show the second page. An earlier version of the
      rewrite rule returned 404 here.
- [ ] **Switch views, race persists.** Bracket → stats → nextup. The race slug stays in every
      URL.
- [ ] **Two races in two tabs.** Reload both. Each keeps its race — precisely what the session
      could not do.
- [ ] **An old bookmark.** `/live/bracket/?race_id=182` → **301** to the new URL. Covers
      bookmarks, the results button, and push notifications already sent.
- [ ] **Numeric path and missing view.** `/live/182/bracket/` → 301 to the slug form.
      `/live/{slug}/` without a view → 301 to the default view.
- [ ] **A view without a race.** Open `/live/bracket/` directly → notice with a link to the
      selection. No fatal, no 404.
- [ ] **A deleted or unpublished race.** A draft's slug in the path → back to the selection as a
      guest, visible as a logged-in editor.
- [ ] **The registration form still works.** `/register/?race_id=182` opens the registration
      with the race preselected. The legacy redirect nearly hijacked this link.
- [ ] **Buttons on a race page.** On `/races/{slug}/`: *Results* leads to the new live URL,
      *Join now!* to your own domain — no longer the hard-wired `copterrace.com`.
- [ ] **The view tabs on a phone (L9).** Open a race on a phone: a row of tabs sits at the foot
      of the screen, the current view marked; one tap opens another view of the same race. On a
      live race the pill floats just above the row, and the last heat scrolls clear of it. On a
      desktop there is no row. The burger menu marks the current view in bold and underlined.
- [ ] **The tabs' order.** They follow the view pages' **Order** (Page Attributes), then their
      titles. To match the header menu — Pilots, Bracket, Stats, Next up — give the four pages
      the orders 1 to 4 in that sequence; with all four at 0 the tabs read alphabetically.
- [ ] **The bracket's titles stay put (1.5.3).** On a phone, swipe the elimination bracket
      sideways to its end: *Elimination: Winner Bracket* and *Looser Bracket* stay at the left
      edge while the races and their lines move; *Qualifying* and *Training* likewise. A line
      that crosses a title runs behind it, not through the text.

## 4 · PWA

The scope stays `/live/`, so installed apps do not need reinstalling.

- [ ] **Check the generated files.** Open `/manifest.json` and `/pwa-sw.js`. Expected: your
      **own** domain instead of `https://domain.com/`, and `start_url` ending in `?resume=1`.
      They are rewritten on the first admin request after deploying. In `/pwa-sw.js`,
      `const CACHE_NAME = CACHE_PREFIX + '…'` starts with the version just deployed — otherwise
      the old worker is still being served.
- [ ] **A live page survives losing reception (L6).** On a phone: open a race, then switch to
      airplane mode and reload. The page stays, with its standing, and the pill says
      *Offline · data from …*. A view of that race never opened on the phone says
      *No connection* instead of the browser's error page. Reception back, a reload no longer
      reports an error — on a live race the pill says *Up to date*, on a finished one it goes away.
- [ ] **Launch the PWA from the home screen.** Lands in the last race viewed. Without a stored
      race (or in a fresh profile), the selection list — the correct fallback.
- [ ] **An existing installation still works.** On a device with the PWA already installed: it
      still launches, without reinstalling.

## 5 · Admin and data upload

- [ ] **Add and delete a registration.** In the registrations screen. Both work as before, now
      with a nonce.
- [ ] **Deleting stays within the race.** Two races with registrations. After deleting in race
      A, race B's registrations are **untouched**.
- [ ] **CSV export.** Via the button in the list. An old bookmark with `&action=download_csv`
      now returns *"link expired"* — expected, that path was unprotected before.
- [ ] **Upload a *new* race from RotorHazard.** One that does not exist yet. The
      `uploads/races/` directory is created on demand; afterwards `{id}-data.json` and
      `{id}-timestamp.json` exist, and from 1.8.0 on `{id}-index.json` and a `{id}-part-….json` per
      section, result heat and class.
- [ ] **Upload an existing race.** Response 200, data updated, the live page shows the new
      values within ten seconds.
- [ ] **Pilot keys (1.5.0).** The registrations list of a race has a *Pilot key* column, filled
      for every registration with an email address. Two registrations with the same address —
      in any capitalisation — show the same key. Settings → RaceManager shows the namespace
      under *Pilot keys*; copy it into `wp-config.php` (see deployment.md, step 5).
- [ ] **Pushes after the answer, at once (1.6.0).** Tools → Site Health → Info → Server shows a
      *cURL version*; without cURL the pushes still go out, but one after another. Follow a
      pilot of a live race on a phone and upload with that pilot up next: the push arrives, and
      with `WP_DEBUG_LOG` on, `debug.log` has `rm_after_response: 1 task(s) after the answer,
      connection closed by litespeed`. *closed by nothing* means the server made the timer
      wait for the pushes, as before 1.6.0.
- [ ] **Compressed uploads are welcome (1.6.0).** After the purge in the next item,
      `curl -s -D - -o /dev/null https://<site>/wp-json/rm/v1/races` answers 401 with the header
      `Accept-Encoding: gzip`. A timer with the connector's compression then sends its uploads
      gzip-compressed; the race looks the same either way.
- [ ] **Answers for timers stay out of the page cache (1.6.1).** Purge LiteSpeed Cache once
      (*Toolbox → Purge All*): an answer it kept before the update stays for up to 7 days. Then
      let the timer press **Load races** and **Download Pilots**, and fetch the same two URLs
      without a login — `curl -s -D - -o /dev/null 'https://<site>/wp-json/rm/v1/get-pilots?race_id=<id>'`
      and `…/rm/v1/races`. Both answer 401, without `X-LiteSpeed-Cache: hit`. Before 1.6.1 both
      answered 200 with the timer's data, out of the cache.
- [ ] **Followers follow the pilot key (1.7.0).** After the update, open any page once; then
      `rm_subscriptions_schema` is `2` (*Tools → Site Health → Info* does not show it; `wp option
      get rm_subscriptions_schema`, or phpMyAdmin's `wp_options`), and `wp_rm_subscriptions` has
      a `pilot_key` column. With a timer whose connector sends `pilot_key`: follow a pilot of a
      live race on a phone, re-create the pilots on the timer (*Clear pilots before download*,
      then **Download Pilots**) and upload. Pushes keep coming for that pilot, and none for
      whoever has their old ID now.
- [ ] **Only what changed reaches a viewer (1.8.0).** On a live race, open a view with the
      browser's developer tools on the network tab, then upload from the timer after a heat. The
      view asks for the timestamp, the index and a handful of `…-part-….json` — that heat, its
      class, the event leaderboard, `current_heat`, `heat_data` — and not for `…-data.json`, and
      shows the new result. A reload in a private window downloads `…-data.json` once, as a first
      visit does. A race uploaded before the update keeps the whole file until its next upload.
- [ ] **Archived races keep their results only (1.8.1).** After the update, open any admin page
      once; then `wp option get rm_archived_races_cleared` has a time, and the `…-data.json` of an
      archived race that had messages shows `"notifications":[]` and no `rm_index`, with no
      `…-part-…` or `…-index.json` beside it. Set a live race to *Archive (Locked)* in the meta box:
      the same, and its timestamp moves. From the timer, **Send** to that race: *Notification
      failed - Race is locked and takes no messages.*, and no push arrives. Set it live again and
      upload: the parts are back.
- [ ] *Optional:* **force a write failure.** Make `uploads/races/` read-only briefly and upload.
      Expected: an **error** instead of `201 Created`, and no empty race left behind. From 1.8.0 on
      the race's timestamp keeps its old time.

## 6 · The cleanup round (PRs #10 and #11)

These four are new since the round above and each has one thing that can only be checked on a
real site.

- [ ] **The upload still works.** The REST endpoints now ask for the `edit_posts` capability
      instead of just "is logged in" (E6). Any account that could upload before already has it,
      so this should be invisible — but do one real upload from the timer, or replay one with
      `curl` and an application password, before a race day depends on it.
- [ ] **PHP is 8.2 or newer.** The plugin now declares `Requires PHP: 8.2` (E7). If the host is
      still on 8.1, WordPress refuses the update — which is the point, but it means switching
      the PHP version first. Tools → Site Health → Info → Server.
- [ ] **No second registration form.** Deactivating and reactivating the plugin used to leave
      another *Event Registration Example* behind every time (E10). Contact → Contact Forms
      shows exactly one, and any duplicates from earlier reactivations can be deleted — check
      first which one the registration page actually embeds.
- [ ] **One `<title>`.** View source on a race page, the front page and an archive: exactly one
      `<title>` element (E9). A page with an `_seo_title` override still shows the override.
- [ ] **No undefined-variable warnings** in `debug.log` from an archive, a search or a 404 page.
- [ ] **Two live shortcodes on one page.** If any page carries two of them, both areas work now
      rather than only the lower one (B3).
- [ ] **The registration address.** Settings → RaceManager shows a *Registration Address* field
      whose placeholder is `registration@copterrace.com`, derived from the site's own domain
      (E8). Leave it empty unless the mailbox lives elsewhere. The existing CF7 form keeps its
      own Mail settings either way — check them under Contact → Contact Forms → Mail if a
      confirmation mail ever bounces.
- [ ] **The gallery opens** from a thumbnail, and the overlay's arrows and swipe work (D2).

---

## 7 · Closing

- [ ] **Review the log.** `wp-content/debug.log` holds no new warnings or errors from the
      plugin.
- [ ] **Watch one race live.** Heats, stats, race log and next-up all refresh, and the pilot
      dropdown lists every pilot once however many updates have arrived — finding D1 in the
      [audit](wordpress-update-audit.md), fixed in [#15](https://github.com/PSi86/wp-racemanager/pull/15).

---

## Known and deliberately not part of this round

Nothing at present. The one entry this section held — the pilot dropdown appending a full set of
options on every data update — was fixed in [#15](https://github.com/PSi86/wp-racemanager/pull/15),
together with the fallback that keeps the selection from going blank when a pilot leaves the
field. `tests/e2e/pilot-selector.cjs` covers both halves; see D1 in the
[audit](wordpress-update-audit.md).
