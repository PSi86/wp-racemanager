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
| **PHP 8.2 or newer** | `minishlink/web-push` v9 pulls in `web-token/jwt-library`, which requires 8.2. Below that, push notifications are unavailable. | Tools → Site Health → Info → Server |
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
and `pwa-sw.js`, and flushes the rewrite rules.

> **Do not deactivate/reactivate just to "run it again".**
> `create_event_registration_cf7_form()` inserts a new *Event Registration Example* form every
> single time it runs — the duplicate check in it is commented out. You get one more CF7 form per
> reactivation, and they are easy to confuse with the real one.

Everything the hook would have done can be done by hand, and section 7 does exactly that.

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
3. **Event dates.** Run the dry run on the settings page and read the numbers before migrating.
   It reports what it would rewrite and lists anything it cannot parse. Take a database snapshot
   first if the host makes that cheap.
4. **PWA files.** Open `https://your-site/manifest.json` and `https://your-site/pwa-sw.js` and
   check that the start URL points at the current live page. If they are stale or missing, the
   WordPress root is not writable — fix the permission and re-save the settings page, which
   regenerates both.

Also worth a look on the first deployment after a longer break:

- **Tools → Site Health** for a PHP version warning.
- Any caching or optimisation plugin: purge it. The live pages are cacheable by design, and a
  cached `/live/{race}/` 301 from before the change will send visitors to the wrong place.
- Browsers cache the `/live/{race}/` → `/live/{race}/{view}/` redirect. Test in a private window.

---

## 8 · Rollback

1. Rename the current `wp-racemanager` out of the way and rename `wp-racemanager-old` back.
   (Route A users: keep a ZIP of the previous release for exactly this.)
2. **Settings → Permalinks → Save** again — the rewrite rules of the new version are still cached
   in the `rm_live_routing` option and in WordPress's own rewrite cache.
3. Only restore the database if the event-date migration ran and produced something unexpected.
   The migration is the sole step in a deployment that writes to existing data; everything else is
   code.

---

## 9 · The RotorHazard side

Nothing about the endpoints changed, so a working timer configuration keeps working:

```
POST /wp-json/rm/v1/upload
GET  /wp-json/rm/v1/get-pilots?race_id=…
POST /wp-json/rm/v1/notify-racers
```

All three authenticate as a WordPress user — in practice an application password on a dedicated
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
