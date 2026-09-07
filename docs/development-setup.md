# Local development environment

How to get from "I only ever tested on the live site" to a WordPress you can break without
anyone noticing. The setup below uses [DDEV](https://ddev.com/), because it brings its own
WP-CLI — which is exactly what the production host does not have, and what makes copying the
production data down painless.

Everything here is done once. Afterwards the daily loop is `ddev start`, edit, `php tests/run.php`.

> **Status: being set up.** Development has moved from the cloud session to VS Code on the
> desktop, and the DDEV project described here now exists. The steps in sections 3 and 4 are
> automated by [`bin/bootstrap-devenv.sh`](../bin/bootstrap-devenv.sh); `bin/dev-doctor.sh`
> (section 6) is what to run afterwards. Every change still has to survive `php tests/run.php`
> plus the manual protocol in [`deployment-test-protocol.md`](deployment-test-protocol.md).

---

## 1 · What you need on your machine

| | Why |
|---|---|
| **Docker** — Docker Desktop, OrbStack or Colima | DDEV runs the site in containers. Nothing is installed into your system PHP. |
| **DDEV**, a current release | `brew install ddev/ddev/ddev` (macOS), `winget install DDEV.DDEV` (Windows, with WSL2), or see the [installation docs](https://docs.ddev.com/en/stable/users/install/ddev-installation/). |
| **Git** | You already have it. |
| **Node 20+ and npm** | Only for `npm run build` (the `race-gallery` block). Runs on the host, not in the container. |
| **VS Code** with the Claude Code extension | The editor side. |

You do **not** need PHP or Composer on the host — DDEV provides both (`ddev php`, `ddev composer`).
Having them locally is convenient for `php tests/run.php`, which needs neither Docker nor WordPress.

---

## 2 · The directory layout

The repository *is* the plugin — its root contains `wp-racemanager.php`. So the plugin gets
cloned **into** a WordPress installation, not the other way round:

```
~/dev/WP_RaceManager/               <- the DDEV project. Not a repository.
├── .ddev/                          <- docroot: wp-app
└── wp-app/                         <- the whole site, and disposable
    ├── wp-admin/  wp-includes/     <- WordPress core, downloaded by DDEV
    ├── wp-config.php               <- the RM_VAPID_* constants go here
    └── wp-content/
        ├── uploads/                <- race JSON files live here
        └── plugins/
            └── wp-racemanager/     <- THIS repository
```

WordPress sits below the docroot rather than at the project root, so the project directory
*holds* an environment instead of *being* one: `wp-app/` can be deleted and rebuilt without
touching anything else. Everything is inside the DDEV project directory, which is what Mutagen
synchronises — a plugin folder bind-mounted in from outside would work but would skip that, and
put the files edited most often on the slowest path.

Three things depend on this layout, so keep it:

- The plugin folder must be named **`wp-racemanager`**. It is the name the deployment ZIP carries,
  and — the reason it actually matters — the name a production database expects: `active_plugins`
  holds `wp-racemanager/wp-racemanager.php`, so under any other name an imported site treats the
  plugin as deactivated.
- The plugin must be a **real directory**, not a symlink. PHP resolves `__FILE__` to the real path,
  so a symlinked plugin folder makes `plugin_dir_url()` compute URLs outside `wp-content/plugins/`
  and every asset 404s.
- `php tests/run.php` finds the WordPress checkout it needs for the `live-links` suite by looking
  three levels above the plugin. In this layout that is `wp-app/`, so the suite runs with no further
  configuration. `WP_CORE_DIR` overrides it if you need a different layout. See
  [`tests/README.md`](../tests/README.md).

---

## 3 · Create the site

Create the project directory and let DDEV configure it. `--project-name` decides the URL, so this
becomes `https://racemanager.ddev.site`:

```bash
mkdir -p ~/dev/WP_RaceManager/wp-app && cd ~/dev/WP_RaceManager

ddev config --project-name=racemanager --project-type=wordpress \
            --docroot=wp-app --php-version=8.3 --nodejs-version=20
```

`--php-version=8.3` is not cosmetic: `minishlink/web-push` v9 pulls in `web-token/jwt-library`,
which requires **PHP ≥ 8.2**. Push notifications silently stay unavailable on anything older.

Put the plugin in place, then start:

```bash
git clone https://github.com/PSi86/wp-racemanager.git \
    wp-app/wp-content/plugins/wp-racemanager

ddev start          # asks for elevation once, to add racemanager.ddev.site to the hosts file
```

Everything after that is mechanical and checkable, so a script does it:

```bash
cd wp-app/wp-content/plugins/wp-racemanager
bin/bootstrap-devenv.sh
```

It downloads and installs core, runs `composer install` for the plugin, makes sure a block theme
is active, installs Contact Form 7 **before** activating the plugin — the activation hook
deactivates the plugin and dies otherwise — switches on pretty permalinks, and builds the `/live/`
pages of section 4. Every step is skipped when it is already done, so it is safe to re-run; it is
the fastest way back after `ddev delete`.

The admin login is `admin` / `admin`. Section 4 explains what the script builds, and stays worth
reading when something is off.

---

## 4 · Set up the live area

The live micro-site is built from ordinary WordPress pages, so it has to exist before anything
under `/live/` works. One parent page plus one child page per view, each holding its shortcode:

```bash
LIVE=$(ddev wp post create --post_type=page --post_title='Live' --post_name=live \
        --post_status=publish --porcelain)

for v in bracket pilots stats nextup; do
  ddev wp post create --post_type=page --post_title="$v" --post_name="$v" \
      --post_parent="$LIVE" --post_status=publish --post_content="[rm_$v]" --porcelain
done

ddev wp option update rm_live_page_id "$LIVE"
ddev wp rewrite flush
```

`bin/bootstrap-devenv.sh` does exactly this, and skips whatever already exists; run it with
`--recreate-live-pages` to tear the four pages down and rebuild them. What it does not do is add a
navigation block to the Live page listing the four views — do that by hand in the editor.

Worth knowing while testing:

- The **view slugs are baked into the rewrite rule**. Adding, renaming or deleting a child page
  rebuilds it automatically, but if `/live/{race}/{view}/` ever 404s, re-save
  **Settings → Permalinks** (or `ddev wp rewrite flush`) first.
- `bracket` is the default view if it exists, otherwise the first child page by menu order.
  `/live/{race}/` redirects there with a 301 — which browsers cache, so test redirects in a
  private window.
- The full URL design is in [`live-urls-and-vapid.md`](live-urls-and-vapid.md).

Finally, open **Settings → RaceManager**:

- confirm the Live page is selected,
- check that the push status does not say *"the minishlink/web-push library could not be found"* — if it
  does, the `composer install` above did not reach the plugin folder,
- check that a VAPID key pair exists. On a fresh install with no subscriptions the activation hook has
  already generated one; otherwise use the *Generate key pair* button below the form.

---

## 5 · Copying the production data down

Testing against real races is worth the effort — most of the interesting cases (integer event
dates, archived races, long heat lists) only exist in real data.

**On the production host**, via Plesk/phpMyAdmin or the hosting backup tool:

1. export the database as SQL (gzip is fine),
2. download `wp-content/uploads/` (the race JSON files live there).

**Locally:**

```bash
ddev import-db --file=~/Downloads/prod.sql.gz
ddev import-files --source=~/Downloads/uploads

# Ask the imported database what production calls itself rather than guessing
# between https://copterrace.com and https://www.copterrace.com
PROD_URL="$(ddev wp option get siteurl)"
LOCAL_URL="$(ddev exec printenv DDEV_PRIMARY_URL)"

# Rewrites URLs inside serialized data too - never do this with a plain SQL find/replace
ddev wp search-replace "$PROD_URL" "$LOCAL_URL" --all-tables --precise
ddev wp cache flush
ddev wp rewrite flush
```

Four things to do immediately after an import, in this order:

1. **Neutralise push.** The import brings the production `rm_vapid` option *and* the live
   subscriptions from `{prefix}rm_subscriptions` with it. A test notification sent from your
   laptop would land on real pilots' phones. Either empty the table
   (`ddev wp db query "TRUNCATE TABLE $(ddev wp config get table_prefix)rm_subscriptions;"`) or
   subscribe only from your own browser after clearing it.
2. **Re-save permalinks**, because the imported `rm_live_routing` option was built for the
   production page IDs.
3. **Check Settings → RaceManager**, in particular that the Live page is still the right one.
4. **Run `bin/dev-doctor.sh`** — section 6 — which reports everything the import cannot bring with
   it: a missing theme, plugins that are active in the database but absent from disk, a PHP version
   that is older than production's.

Going the other way — pushing local data up — is not part of any workflow here. Production data
flows down only.

---

## 6 · Making the copy behave like production

A database import brings the pages, the races, the options and the Contact Form 7 forms. What it
does **not** bring is the code around them — the theme, the other plugins, the PHP version. Those
are what decide whether a bug reproduces locally.

### The table prefix has to match — before the import

DDEV creates the site with the prefix `wp_`. If production uses a different one, the imported
tables are simply invisible to WordPress and you get a fresh install screen. Read the prefix out of
the dump before importing:

```bash
grep -m1 -o 'CREATE TABLE `[a-z0-9_]*options`' prod.sql
ddev wp config set table_prefix 'thatprefix_' --type=variable
```

### Same theme, same plugins

After importing, the database says what production runs; the filesystem may not have it:

```bash
ddev wp option get template          # parent theme folder
ddev wp option get stylesheet        # active (child) theme folder
ddev wp option get active_plugins --format=json
```

`bin/dev-doctor.sh` does that comparison for you, together with the checks below:

```bash
bin/dev-doctor.sh              # report only
bin/dev-doctor.sh --install    # also pull missing themes/plugins from wordpress.org
```

Anything it cannot fetch from wordpress.org — premium plugins, a custom child theme — has to be
copied out of `wp-content/plugins/` and `wp-content/themes/` on production via SFTP. That is a
one-time copy; afterwards `git pull` on this plugin is all that changes.

### The theme must be a block theme

Not a preference — a dependency. `rm_print_js_module_config()` is hooked to `wp_head` from *inside*
the live shortcodes, which only works because a block theme renders the template before `wp_head()`
runs (finding **B3** in [`wordpress-update-audit.md`](wordpress-update-audit.md)). Under a classic
theme the live pages lose their JavaScript configuration and fail in a way production never shows.
Use production's own theme if you can get it, otherwise any block theme, e.g. Twenty Twenty-Five.

### HTTPS is part of the test

DDEV serves `https://<project>.ddev.site` with a locally trusted certificate. Keep it: service
workers, the PWA install prompt and `PushManager.subscribe()` all require a secure context, so on
plain HTTP you cannot test the half of this plugin that matters most.

### Match the PHP version

Read production's from **Tools → Site Health → Info → Server**, then set the same in
`.ddev/config.yaml` (`php_version: "8.3"`) and `ddev restart`. A plugin that works on 8.3 locally
and dies on the host's 8.1 is exactly the class of bug this environment exists to catch.

### What should deliberately *not* match

- **The VAPID keys.** Generate a separate pair locally. Sharing production's means a local mistake
  can reach real subscribers' devices.
- **The push subscriptions.** They come with the database import and point at real phones. Clear
  `{prefix}rm_subscriptions` before testing notifications — `dev-doctor.sh` warns when the table is
  not empty.
- **Outgoing mail.** DDEV captures everything in Mailpit (`ddev launch -m`), so the CF7
  confirmation mails stay local.

### Reading production's structure without a database dump

Everything the live area's routing depends on is public, so you can check the local copy against it
from a browser:

| URL (production is `https://copterrace.com`) | What it tells you |
|---|---|
| `/manifest.json` | The PWA `scope` and `start_url` — i.e. the live page's real path. |
| `/pwa-sw.js` | The generated service worker, with the same values. |
| `/wp-json/wp/v2/pages?per_page=100&_fields=id,parent,slug,link,menu_order` | The full page tree. The children of the live page **are** the view slugs, in the order the rewrite rule uses. |
| `/wp-json/` | The registered REST namespaces — `rm/v1` plus whatever other plugins expose. |
| `/wp-sitemap.xml` | Every public URL, useful for spotting what else lives under `/live/`. |
| page source, `/wp-content/themes/<slug>/` in the asset URLs | The theme folder name. |

Recreate the same slugs locally and the URLs under test are identical to production's, which is
what makes a redirect or a navigation bug reproducible at all.

## 7 · The daily loop

```bash
ddev start                 # boots the site
ddev launch                # opens it in a browser

bin/dev-doctor.sh          # is the local site still shaped like production?

php tests/run.php          # the whole suite, no Docker needed
php tests/run.php live     # only the live-routing / live-links / live-shortcodes suites
php tests/run.php -v       # print each suite's output

npm install                # once
npm run build              # blocks-src/ -> blocks/
npm run start              # watch mode while working on the race-gallery block
```

No PHP on the host, or an older one than the container runs? Run the suites inside DDEV instead —
the `vapid` suite exercises the real push library and therefore needs PHP 8.2 like the plugin does:

```bash
ddev exec -d /var/www/html/wp-app/wp-content/plugins/wp-racemanager php tests/run.php
```

Useful DDEV commands for this plugin specifically:

| Command | What for |
|---|---|
| `ddev launch -m` | Mailpit — every CF7 registration mail lands there instead of a real inbox. |
| `ddev xdebug on` / `off` | Step debugging. VS Code needs a `Listen for Xdebug` launch configuration on port 9003; DDEV's docs have the ready-made snippet. |
| `ddev logs -f` | PHP errors and warnings as they happen. |
| `ddev snapshot` / `ddev snapshot restore --latest` | Database checkpoint before trying a migration — for example the event-date migration on the settings page. |
| `ddev wp ...` | Any WP-CLI command. |
| `ddev restart` | After changing `.ddev/config.yaml`. |
| `ddev delete -O` | Throw the database and the DDEV project away. The files stay, so to start truly fresh delete `wp-app/` too — move the plugin folder out first, then back in, and re-run `bin/bootstrap-devenv.sh`. |

---

## 8 · VS Code and Claude Code

Open **the plugin folder** (`wp-content/plugins/wp-racemanager`) as the workspace root, not the
WordPress root. `CLAUDE.md`, `docs/` and `tests/` sit there, and Claude Code picks up `CLAUDE.md`
from the workspace root automatically.

Recommended extensions: PHP Intelephense, PHP Debug (Xdebug), EditorConfig.

Two things make Claude Code useful here:

- `php tests/run.php` is fast and needs nothing installed — it is the fastest correctness signal
  in the project.
- The open work is tracked in [`wordpress-update-audit.md`](wordpress-update-audit.md) with a
  status per item. "Pick the next open item from the audit" is a complete instruction.

If you want the container's PHP inside VS Code, run `ddev ssh` in the integrated terminal, or use
the Dev Containers extension against `.ddev/`.

---

## 9 · Testing what a real timer would send

RotorHazard talks to three REST endpoints, all of which require an authenticated WordPress user:

```
POST /wp-json/rm/v1/upload             race data upload
GET  /wp-json/rm/v1/get-pilots         registration download
POST /wp-json/rm/v1/notify-racers      push notification
```

Create an application password for the admin user (**Users → Profile → Application Passwords**)
and replay a real upload against the local site:

```bash
curl -u 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' \
     -H 'Content-Type: application/json' \
     --data @race.json \
     "$(ddev exec printenv DDEV_PRIMARY_URL)/wp-json/rm/v1/upload"
```

Take `race.json` from `wp-content/uploads/` on the production site — that is exactly what the
timer sent.

---

## 10 · Alternatives to DDEV

| | Verdict |
|---|---|
| **[`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)** (`npx wp-env start`) | Lighter, and it is the tool the block editor team uses. Also ships WP-CLI (`npx wp-env run cli wp ...`). Weaker at importing a production database and at mail capture. Fine if the block work is your main interest. |
| **Local by Flywheel / Studio by WP Engine** | Click-through, no Docker knowledge needed. Getting a git-managed plugin folder into them is a manual step, and there is no `ddev exec composer`. |
| **A staging subdomain on the production host** | Closest to the real thing, and the only place where the *host's* PHP version and file permissions are truly reproduced. Slow loop, and it needs a second database. Worth having in addition, not instead. |

---

## Related documents

- [`deployment.md`](deployment.md) — putting a build on the production host, without WP-CLI.
- [`deployment-test-protocol.md`](deployment-test-protocol.md) — what to click through afterwards.
- [`tests/README.md`](../tests/README.md) — the automated suites.
