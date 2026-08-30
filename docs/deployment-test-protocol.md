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

## 4 · PWA

The scope stays `/live/`, so installed apps do not need reinstalling.

- [ ] **Check the generated files.** Open `/manifest.json` and `/pwa-sw.js`. Expected: your
      **own** domain instead of `https://domain.com/`, and `start_url` ending in `?resume=1`.
      They are rewritten on the first admin request after deploying.
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
      `{id}-timestamp.json` exist.
- [ ] **Upload an existing race.** Response 200, data updated, the live page shows the new
      values within ten seconds.
- [ ] *Optional:* **force a write failure.** Make `uploads/races/` read-only briefly and upload.
      Expected: an **error** instead of `201 Created`, and no empty race left behind.

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
- [ ] **The gallery opens** from a thumbnail, and the overlay's arrows and swipe work (D2).

---

## 7 · Closing

- [ ] **Review the log.** `wp-content/debug.log` holds no new warnings or errors from the
      plugin.
- [ ] **Watch one race live.** Heats, stats, race log and next-up all refresh.
      **Note:** the pilot dropdown still accumulates duplicates — known, finding D1 in the
      [audit](wordpress-update-audit.md), not yet fixed.

---

## Known and deliberately not part of this round

**The pilot dropdown** (`js/rm-m-pilotSelector.js`) appends a full set of options on every data
update without clearing first. It only shows on a **live** race — an archived race populates
exactly once — and every page load resets it, which is why it has never been noticed in
practice.

The two fixes belong together: rebuilding the list alone would make the selection go blank when
a pilot leaves the field, because today the stale option is what keeps it selected. See D1 in
the [audit](wordpress-update-audit.md).
