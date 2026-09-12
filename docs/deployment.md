# Deploying to the production host

The production site is shared hosting **without WP-CLI and without a reliable shell**, so every
step here works from a browser plus an SFTP client. Where a shell *is* available, the faster
variant is listed as an alternative.

The repository has grown past "just the plugin": it now also carries tests, documentation and
build sources that have no business on a web server. Section 2 is about separating the two.

---

## 1 · What the host must provide

| Requirement | Why | How to check without WP-CLI |
|---|---|---|
| **PHP 8.2 or newer** | `minishlink/web-push` pulls in `web-token/jwt-library`, which requires 8.2, and the plugin header declares it — so below that WordPress refuses to activate at all, rather than merely losing push. | Tools → Site Health → Info → Server |
| `curl`, `openssl`, `mbstring`, `json` extensions | Web Push signing and delivery. | same screen |
| **Pretty permalinks** | The `/live/{race}/{view}/` rewrite rule cannot work with plain permalinks. | Settings → Permalinks |
| **Contact Form 7, active** | The activation hook refuses to run without it. | Plugins |
| Write access to the **WordPress root** | `manifest.json` and `pwa-sw.js` are generated there. They must sit at the root for the PWA's scope. | after deploying, both files exist and are current |
| Write access to `wp-content/uploads/` | Race JSON files are written there by the upload endpoint. | Media library works |

---

## 2 · Building the artifact

### What ships

```
wp-racemanager.php   includes/   js/   css/   img/   assets/   templates/   blocks/
composer.json        vendor/  (see section 5)
```

### What does not

| Not shipped | Why |
|---|---|
| `tests/` | Development only. Harmless — every file refuses to run over HTTP — but there is no reason to publish it. |
| `docs/`, `CLAUDE.md` | Documentation. |
| `blocks-src/`, `webpack.config.js`, `package.json`, `package-lock.json` | Build inputs. The *built* blocks in `blocks/` are what WordPress loads. |
| `.devcontainer/`, `.github/`, `.gitignore`, `.gitattributes` | Repository infrastructure. |
| `.git/` | Never upload this. It contains the full history, including anything ever committed by mistake. |

`.gitattributes` marks all of these `export-ignore`, so `git archive` leaves them out
automatically.

### The build script

```bash
bin/build-plugin-zip.sh                  # builds from HEAD
bin/build-plugin-zip.sh main             # or from any ref
bin/build-plugin-zip.sh --no-vendor      # without the Composer dependencies
```

It produces `build/wp-racemanager-<ref>.zip` containing a single top-level folder
`wp-racemanager/` — the shape WordPress expects from an uploaded plugin ZIP. The script exports
the tracked files with `git archive`, installs the Composer dependencies with `--no-dev` into the
export, and strips the packages' own `.git`/`.github` directories. That last step matters: when a
dist download is unavailable Composer silently falls back to a *source* install, and the checked-out
repositories turn a 2.5 MB artifact into a 26 MB one.

Build the blocks before packaging if you touched `blocks-src/`:

```bash
npm install && npm run build
git status --short blocks/      # commit the result - blocks/ is tracked
```

> `composer.lock` is currently git-ignored. The build script uses the one in your working copy if
> it is there, so a build from a checkout you have tested reproduces those exact versions — but a
> build from a fresh clone resolves them anew. Committing `composer.lock` would remove that
> difference; until then, build from a working copy whose dependencies you have actually run.

---

## 3 · Before you deploy — do not skip

1. **Write down the VAPID keys that are live right now.** Everything else on this list is
   recoverable; this is not. If subscribers exist and the keys change, every one of them silently
   stops receiving notifications and has to subscribe again from the device that subscribed.
   - Older installs kept them in the source: look for `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` in
     the **installed** `includes/pwa-subscription-handler.php` on the server (via SFTP), or in
     `wp-config.php`.
   - Newer installs keep them in the `rm_vapid` option. Settings → RaceManager shows the public
     key and where it comes from.
   - Copy both keys into your password manager **before** anything is overwritten.
2. **Database backup.** Plesk/cPanel backup, or a phpMyAdmin export. The registrations
   (`{prefix}rm_registrations`) and subscriptions (`{prefix}rm_subscriptions`) tables are not in
   any WordPress export.
3. **Download `wp-content/uploads/`** — or at least the race JSON directory.
4. **Keep the old plugin folder.** Rename it to `wp-racemanager-old` via SFTP rather than deleting
   it; that is the rollback.
5. **Do not deploy on a race day.** The live area is the part being changed.

---

## 4 · Getting the files onto the server

### Route A — upload the ZIP in the WordPress admin (recommended)

1. **Plugins → Add New Plugin → Upload Plugin**, choose the ZIP, **Install Now**.
2. WordPress detects the existing installation and shows a comparison of current vs. uploaded
   version. Choose **Replace current with uploaded**.
3. The plugin stays active. WordPress replaces the folder wholesale, so files removed in this
   release genuinely disappear — which SFTP does not do for you.

The one catch: on a *replace*, the **activation hook does not run again**. Section 6 lists what
that means you have to do by hand.

If the upload fails with "the uploaded file exceeds the upload_max_filesize directive", use
route B. The ZIP is around 2.5 MB with `vendor/` included, and 2 MB is still a common default on
shared hosting.

### Route B — SFTP or the Plesk file manager

1. Upload the *new* folder next to the old one, as `wp-content/plugins/wp-racemanager-new/`.
   Uploading into the live folder means the site runs half-old, half-new code for the duration of
   the transfer.
2. Rename `wp-racemanager` → `wp-racemanager-old`, then `wp-racemanager-new` → `wp-racemanager`.
   WordPress identifies a plugin by its folder plus main file, so the swap keeps it active.
3. Delete `wp-racemanager-old` **after** the checks in section 7 pass.

Renaming a plugin's folder while it is active can, on some hosts, make WordPress deactivate it
because the path in the `active_plugins` option no longer resolves. If that happens, reactivate
it — and read section 6 first, because reactivating *does* run the activation hook.

### Route C — git on the server

Only if the host offers SSH. Then the plugin folder can be a working copy:

```bash
cd wp-content/plugins/wp-racemanager
git fetch origin && git checkout main && git reset --hard origin/main
composer install --no-dev
```

Convenient, but it publishes `tests/`, `docs/` and `.git/` unless the web server is configured to
deny them. Prefer route A.

---

## 5 · The `vendor/` question

`minishlink/web-push` has to be reachable, and the plugin looks for an autoloader in this order:

1. `wp-content/plugins/wp-racemanager/vendor/autoload.php` — **preferred**
2. `vendor/autoload.php` four levels above the plugin, i.e. next to the WordPress root
3. `ABSPATH . vendor/autoload.php` and one level above it

Historically this site kept `vendor/` in the hosting root, outside the plugin, because that is
where it was first installed. That still works — but it means an update of the plugin does not
update its dependencies, and nobody remembers the directory exists.

**Recommendation:** ship `vendor/` inside the plugin ZIP (the default of the build script). The
plugin-local copy wins over the external one, so the switch takes effect the moment the ZIP is
installed, and the old external directory can be deleted once Settings → RaceManager still
reports push as available.

If you would rather keep it external, build with `--no-vendor` and leave the outside directory
alone.

---

## 6 · First install versus update

| | Runs the activation hook? |
|---|---|
| First install, then **Activate** | yes |
| ZIP upload → *Replace current with uploaded* | **no** |
| Manual folder swap, plugin stays active | **no** |
| Deactivate → Activate | yes |

The activation hook creates the two custom tables, bootstraps VAPID keys, writes `manifest.json`
and `pwa-sw.js`, and flushes the rewrite rules. From 1.7.0 on, the subscriptions table is also
brought up to date on the first request after an update (`rm_maybe_upgrade_subscriptions_table()`,
recorded in the option `rm_subscriptions_schema`), so its `pilot_key` column needs no
reactivation.

**From 1.8.1 on, the first admin page after an update clears the archived races**
(`rm_maybe_clear_archived_races()`, recorded in the option `rm_archive_schema`). Every race that is
not live loses its race log and, from 1.9.0 on, its push subscriptions, from the database **for
good**, and its files are written again without the log, and without the parts 1.8.0 may have
left: the whole file and the timestamp, with a new time. The database backup of section 3 is the
only way back. A race whose files carry no log is not written again. An AJAX request does not run
it, since the live pages make those; a deployment by SFTP alone runs it with the first admin page
anyone opens. To run it again, delete the option. A site that ran 1.8.1's version, recorded as
`rm_archived_races_cleared`, runs it once more for the subscriptions.

**From 1.9.0 on, the first request after an update archives every live race whose end and last
upload are more than a day past** ([`race-status.md`](race-status.md)). The hourly run is scheduled
for now, and WordPress runs it on that same request: those races lose their race logs and push
subscriptions at once, as above. **Before updating, look at the admin's race list** — *Race
Status* — and correct the end of any live race that is not over, or archive by hand the ones that
are. `define( 'RM_AUTO_ARCHIVE', false );` in `wp-config.php` switches the archiving off.

**A change of a race's state empties LiteSpeed's page cache** (1.9.0). Production serves the home
page, `/live/` and the race views from it (measured on 2026-09-12), and the state is in their
markup; a change used to show only once the cached pages expired.

**Deactivate → Activate is safe again**, and it is the simplest way to run the hook after a ZIP
replace. It used to be the thing not to do: `create_event_registration_cf7_form()` inserted
another *Event Registration Example* form on every run, because the duplicate check in it was
commented out. That is finding **E10**, fixed in [#10](https://github.com/PSi86/wp-racemanager/pull/10)
— the function now returns the existing form, and `tests/suites/activation.php` asserts
"reactivating creates nothing".

Everything the hook does can also be done by hand, and section 7 does exactly that.

---

## 6a · The September 2026 update — what made it different

> **Done.** Production serves 1.2.0, measured on 2026-09-10: `/live/winter-whooprace-2025/bracket/`
> answers 200, the old `/live/bracket/?race_id=2402` answers 301 to it, and the plugin's assets
> carry `?ver=1.2.0`. The section stays as the record of what made that update unusual; it was
> written before it, in the present tense of the time.

Production still runs the June 2025 code. Confirmed rather than assumed: on
`copterrace.com`, `/live/bracket/?race_id=2402` answers 200 while
`/live/winter-whooprace-2025/bracket/` answers 404, so the path-based router has never been
deployed there. This is a year of accumulated change, and four things about it are not routine.

**1 · PHP 8.2 is now a hard floor — check before anything else.** `minishlink/web-push` 11 pulls
in `web-token/jwt-library`, which requires it, and the plugin header declares it. WordPress
refuses to activate a plugin whose PHP requirement the server does not meet, so on an older PHP
the update does not half-work, it stops. **Tools → Site Health → Info → Server** before you
start; the host serves LiteSpeed and does not put the version in a response header.

**2 · Two Composer dependencies crossed majors, and the ZIP settles it.** `minishlink/web-push`
9 → 11, and 11 no longer depends on Guzzle: it resolves a PSR-18 client through
`php-http/discovery` at construction time, so a dependency set without one leaves
`class_exists()` reporting the library as present while every notification throws. `composer.json`
requires `guzzlehttp/guzzle` explicitly for that reason. The artifact ships its own `vendor/`, and
`rm_push_library_available()` looks there **first** — before any `vendor/` above the WordPress
root — so the shipped set wins over whatever older one the server may still carry. Nothing has to
be removed by hand, but see section 5 if this site was ever set up with the library outside the
plugin.

**3 · The live URLs change shape, and only a permalink flush completes it.** Production currently
serves `/live/{view}/?race_id={id}`; the deployed code serves `/live/{race-slug}/{view}/` and
redirects the old form to it. The rewrite rule is built from the slugs of the live page's
children, and those already match — `bracket`, `pilots`, `stats`, `next-up` under `live`. So no
page work is needed, but **Settings → Permalinks → Save** is not optional, and a cached 301 from
before the change will send visitors to the wrong place until the cache is purged.

**4 · The blocks moved to `apiVersion: 3`.** All seven, and every one of them is dynamic, so no
stored post content changes and nothing can be invalidated. What is worth a look after the
deployment is the editor: open a post containing a RaceManager block and check the browser
console is free of block errors. The `race-gallery` block drives the classic media modal, which
was verified against WordPress 7.1's iframed editor.

Beyond that, the ordinary sequence in sections 3, 4 and 7 applies unchanged.

## 6b · 1.11.0 — nationality and photo in the registration form

1.11.0 shows a pilot's flag next to the callsign and the photo in the standing, from two optional
fields of the registration form. The plugin creates its example form only once, so **a site's own
form gets them by hand** (Contact Form 7 → the form → Form):

```
Nationalität (optional): [rm_country pilot_country_1]

Foto (optional, JPG, PNG oder WebP, bis 5 MB): [file pilot_photo_1 limit:5mb filetypes:jpg|jpeg|png|webp]
```

The field names are what the plugin reads; the labels are free. Both are published under the consent
`acceptance-media` alone, and only for a registration that ticked it, so **its text has to name the
nationality**: "Ich bin mit der Veröffentlichung von Name, Alter, Nationalität und Bild im Rahmen des
Wettkampfs einverstanden." A registration without the consent removes what the pilot gave before,
and so does deleting the pilot's last registration in the admin: the photo from every race at once,
since there is one file per pilot; the country from the files of a race when it is next written,
which for an archived race is not again. The confirmation mail can list the
country with `Nationalität: [pilot_country_1]`; the photo is never attached to it.

What the host needs:

- **GD or Imagick** for the photos. Without either, a photo is ignored and the registration stored
  as it is.
- **exif** for GD to turn a phone photo the way it was taken; Imagick reads the orientation itself.
- **intl** for the country names in the site's language; without it they are English.

Tools → Site Health → Info → Media Handling and Server show all three. The photos go to
`wp-content/uploads/rm-pilots/{pilot key}.jpg`, 256 px square at most, without the camera's metadata,
and the directory gets an `index.php`. The flags ship with the plugin (`assets/flag-icons-7.5.0/`,
250 SVGs, about 2 MB, MIT).

A race's files carry its pilots' flags and photos from its next write on: a live race with the next
upload, and a race archived before 1.11.0 not at all, until it is written again. Only pilots whose
timer sends their pilot key can have them: the RotorHazard connector does from its 2.0.0 betas on.
The timer's own `/bracketview` shows no flags.

---

## 7 · After deploying

Do these four in order, then work through
[`deployment-test-protocol.md`](deployment-test-protocol.md), which covers the functional side.

1. **Settings → Permalinks → Save.** Nothing else re-registers the `/live/{race}/{view}/` rule
   after a code change. Skipping this is the single most common cause of "the live area is 404 on
   every race".
2. **Settings → RaceManager.** Check that
   - the Live page is still selected,
   - push reports as available (i.e. the Composer library was found),
   - the VAPID public key matches the one you wrote down in section 3. If the site lost its keys,
     paste the saved pair into the *Import existing keys* field — do **not** generate new ones
     while subscriptions exist.

   On production the better home for the pair is `wp-config.php`, which keeps the private key out
   of the database and out of every database export:

   ```php
   define( 'RM_VAPID_PUBLIC_KEY',  '…' );
   define( 'RM_VAPID_PRIVATE_KEY', '…' );
   define( 'RM_VAPID_SUBJECT',     'mailto:you@example.com' );
   ```

   The constants take precedence over the stored option, and the settings page then shows the keys
   as read-only.
3. **Event dates.** Run the dry run on the settings page and read the numbers before migrating.
   It reports what it would rewrite and lists anything it cannot parse. Take a database snapshot
   first if the host makes that cheap.
4. **PWA files.** Open `https://your-site/manifest.json` and `https://your-site/pwa-sw.js` and
   check that the start URL points at the current live page. If they are stale or missing, the
   WordPress root is not writable — fix the permission and re-save the settings page, which
   regenerates both.
5. **Pilot namespace** (from 1.5.0). **Settings → RaceManager** shows it under *Pilot keys*; it is
   created on the first admin request after the update. Copy it into `wp-config.php`:

   ```php
   define( 'RM_PILOT_NAMESPACE', '…' );
   ```

   It is what every pilot key is derived from. In the database it goes with every backup; in
   `wp-config.php` it also survives a site rebuilt from scratch. Never replace it with a new one:
   every pilot would get a new key, and RotorHazard would take each for a new pilot. See
   [`pilot-identity.md`](pilot-identity.md).

Also worth a look on the first deployment after a longer break:

- **Tools → Site Health** for a PHP version warning.
- Any caching or optimisation plugin: purge it. The live pages are cacheable by design, and a
  cached `/live/{race}/` 301 from before the change will send visitors to the wrong place.
- **LiteSpeed Cache: keep `/wp-json/rm/v1/` out of it.** Its shipped defaults cache REST answers
  for 7 days (*Cache REST API*), and it takes a timer's request, which logs in with an
  application password, for a guest's. Before 1.6.1 that put a timer user's race list and whole
  registration lists into the cache for anyone (found on production on 2026-09-12). 1.6.1 marks
  every `rm/v1` answer as not to be cached. Purge once after updating to it: what was stored
  before stays until it expires. Adding `/wp-json/rm/v1/` under *Cache → Excludes → Do Not Cache
  URIs* costs nothing and holds whatever a later version of either plugin does.
- Browsers cache the `/live/{race}/` → `/live/{race}/{view}/` redirect. Test in a private window.

### Assets, and which of them a release actually refreshes

Everything the plugin **enqueues** — every stylesheet, every script, every script module —
carries `WP_RACEMANAGER_VERSION`, so bumping the plugin version refreshes all of them at once. The
`asset-versions` suite reads every enqueue in the source and fails if a hand-written literal or a
missing version creeps back in; the bundled Swiper is versioned by the release in its path, and
the legacy `[rm_viewer]` shortcode is exempt by name. That is why the version bump is not optional
for a release: without it, returning visitors keep the cached assets and run the previous release
against the new PHP.

The exception is below, and it is the one to check by hand.

### The JS modules that carry no version, and why they can go stale

WordPress appends `?ver=` to the module it enqueues, but **not** to anything that module imports.
Every view enqueues one module and reaches the rest through relative `import` statements, so
`js/rm-m-dataLoader.js` — which every view depends on and which several updates have now
rewritten — is fetched by a URL with no cache-buster on it at all. Its freshness rests entirely
on what the web server sends for a static `.js` file.

Check it once per host, not per deploy:

```bash
curl -sI https://<site>/wp-content/plugins/wp-racemanager/js/rm-m-dataLoader.js \
  | grep -i -E 'cache-control|expires|etag|last-modified'
```

The service worker (L6) does not change this. It goes to the network first for every file, versioned
or not, through the browser's own cache like a page would; its copies answer only when the network
does not.

`no-cache` or a short `max-age` means the browser revalidates and picks the new file up on the
next load; that is what the local DDEV nginx sends. A long `max-age` with no revalidation means
returning visitors keep running the **old** loader against the new PHP until it expires — and
because a stale loader still works, nothing looks broken, it just behaves like the previous
release. If the header is long-lived, purge the optimisation plugin's asset cache as well as its
page cache, and verify in a private window rather than a reloaded tab.

1.8.0 was built so that this costs nothing but the saving. A loader from before it, still cached,
downloads the whole file on every change as it always did, and hands on the index the file now
carries, which no module reads. A 1.8.0 loader meeting files from before — a race not uploaded to
since, or a rollback — downloads the whole file too: it turns to the index only once a whole file
has carried one, and treats an index older than the timestamp as not there.

1.9.1 changes the loader in `subscribe()` alone: a subscriber that throws on the cached data no
longer takes its module's start-up with it. A cached loader from before behaves as before, and the
bracket view guards itself now (a class it cannot draw is drawn as a row), so a stale copy costs
nothing new. `rm-m-displayHeats.js` is also reached unversioned, through the next-up view's import;
the same holds for it.

1.10.0 adds three modules reached only by relative imports: `rm-m-bracketModel.js` (from
`rm-m-displayHeats.js` and the standing), `rm-m-bracketStandings.js` and, on the next-up view,
`rm-m-displayStandings.js`. A browser that still holds the 1.9 `rm-m-displayHeats.js` never asks
for them and draws as before. The model is imported as a whole (`import * as`) and only ever gains
exports, so an older cached copy of it next to a newer view costs a missing feature, not the page.

1.10.0 also changes the bracket view's markup: `[rm_bracket]` renders one `#raceclass-sections`
that the classes are drawn into, `#class-<id>-display` each, and `#standings-display` under it,
instead of the fixed `#elimination-display`, `#qualifying-display` and `#training-display`. Custom
CSS in the theme or the Site Editor that targets the old ids no longer applies; look for it before
deploying.

1.10.1 changes `rm-m-bracketModel.js` and `rm-m-bracketStandings.js` alone: a Chase the Ace round
flown after the deciding one no longer counts. Both are reached unversioned; a browser that still
holds the 1.10.0 copies counts such a round until it fetches them again - its wins in the final
("3/2") and, without the timer's ranking, the places behind the winner. Nothing else differs.

1.11.0 gives `rm-m-bracketModel.js` a new export, `pilotProfile()`, which `rm-m-displayHeats.js`
and `rm-m-displayStandings.js` check for before they call it. A browser holding an older model shows
no flags and photos until it fetches the new one; the next-up view reaches both displays
unversioned as well, with the same effect. Nothing breaks.

---

## 8 · Rollback

1. Rename the current `wp-racemanager` out of the way and rename `wp-racemanager-old` back.
   (Route A users: keep a ZIP of the previous release for exactly this.)
2. **Settings → Permalinks → Save** again — the rewrite rules of the new version are still cached
   in the `rm_live_routing` option and in WordPress's own rewrite cache.
3. Only restore the database if the event-date migration ran and produced something unexpected,
   or if the race logs and push subscriptions of the archived races are wanted back (1.8.1 and
   1.9.0 clear them, and 1.9.0 archives races past their end, see section 6).
   Those two are the steps in a deployment that write to existing data; everything else is code.
   The clearing also rewrote those races' files; the download of section 3 has them as they were.
4. Going back from 1.8.0 or later, the races' `{id}-index.json` and `{id}-part-….json` stay in
   `uploads/races/`. The older plugin neither writes nor reads them, and a later update writes them
   anew; they can be deleted, or left.

---

## 9 · The RotorHazard side

The endpoints a timer uses:

```
POST /wp-json/rm/v1/upload?race_id=…
GET  /wp-json/rm/v1/races
POST /wp-json/rm/v1/races
GET  /wp-json/rm/v1/get-pilots?race_id=…
POST /wp-json/rm/v1/notify-racers
```

The two `races` routes and the upload's `race_id` came with choosing the race on the timer. A timer
with an older connector keeps working: its upload carries no `race_id` and goes by title, as
before — for one more release.

All of them authenticate as a WordPress user — in practice an application password on a dedicated
account. If uploads start failing after a deployment, check in this order: the application
password still exists, the account still has `edit_post` on that race, and the uploads directory
is writable. The upload endpoint reports a directory it cannot create rather than failing
silently, so the response body says which one it is.

---

## Related documents

- [`development-setup.md`](development-setup.md) — the local environment; test there first.
- [`deployment-test-protocol.md`](deployment-test-protocol.md) — the manual checks after a deploy.
- [`live-urls-and-vapid.md`](live-urls-and-vapid.md) — why the live URLs and the keys work the way
  they do.
