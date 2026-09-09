# Local development environment

How to get from "I only ever tested on the live site" to a WordPress you can break without
anyone noticing. The setup below uses [DDEV](https://ddev.com/), because it brings its own
WP-CLI — which is exactly what the production host does not have, and what makes copying the
production data down painless.

Everything here is done once. Afterwards the daily loop is `ddev start`, edit, `php tests/run.php`.

> **Status: in use.** Development happens in VS Code against a local DDEV site, with the
> repository beside the site rather than buried inside it and a symlink putting it where
> WordPress looks for a plugin (section 2). The steps in sections 3 and 4 are automated by
> [`bin/bootstrap-devenv.sh`](../bin/bootstrap-devenv.sh); `bin/dev-doctor.sh` (section 6) is what
> to run afterwards. Every change still has to survive `php tests/run.php` plus the manual
> protocol in [`deployment-test-protocol.md`](deployment-test-protocol.md).

---

## 1 · What you need on your machine

| | Why |
|---|---|
| **Docker** — Docker Desktop, OrbStack or Colima | DDEV runs the site in containers. Nothing is installed into your system PHP. If you take the WSL 2 route below, switch Docker Desktop's **Settings → Resources → WSL Integration** on for the distro, otherwise the daemon socket never reaches it. |
| **DDEV**, a current release | Windows: `winget install DDEV.DDEV`, which lands in `%LOCALAPPDATA%\Programs\DDEV`. macOS: `brew install ddev/ddev/ddev`. Linux and WSL 2: `curl -fsSL https://ddev.com/install.sh \| bash`. See the [installation docs](https://docs.ddev.com/en/stable/users/install/ddev-installation/). |
| **Git** | On Windows the Git Bash that comes with Git for Windows is what runs `bin/*.sh`. |
| **Node 20+ and npm** | Only for `npm run build` (the `race-gallery` block). Runs beside the project, not in the container. |
| **VS Code** with the Claude Code extension | The editor side. Section 8. |
| **On Windows, optionally: WSL 2** with a current Ubuntu | Faster, and it removes a class of Windows-only annoyances. Not required — see the trade-off below. |

You do **not** need PHP or Composer yourself — DDEV provides both (`ddev php`, `ddev composer`).
Having them installed is convenient for `php tests/run.php`, which needs neither Docker nor
WordPress; `apt install php-cli` covers it.

### Windows: on `C:` or inside WSL 2

Both work, and the layout in section 2 is the same either way. The only real difference is *where
the project directory lives*; everything else follows from that.

**On `C:` — what this setup uses.** Docker Desktop's daemon runs inside its own Linux VM, so a
project on `C:` is reachable from a container only through a filesystem translation layer. That is
slow enough that DDEV switches on Mutagen, a background process that copies the project into a
Docker volume and syncs changes both ways. It works, and a plugin this size is nowhere near the
sizes where Mutagen struggles. What it costs:

- A second copy that can drift. `ddev mutagen reset` followed by `ddev start` is the repair, and
  `ddev mutagen st racemanager -l` shows what the sync currently believes.
- Mutagen runs in `portable` symlink mode, which constrains how the plugin is linked into the site.
  Section 2 spells this out; it is the one thing that will bite if you change the layout.
- Git Bash rewrites anything that looks like an absolute POSIX path before a Windows executable
  sees it, so `ddev exec ls /var/www/html` turns into a lookup under `C:\Program Files\Git`. That is
  why `bin/bootstrap-devenv.sh` sets `MSYS_NO_PATHCONV`, and why you want that exported in any
  shell where you run `ddev` by hand.
- `core.autocrlf=true` leaves CRLF in the working tree, which the container then has to tolerate.
  The `*.sh text eol=lf` rule in `.gitattributes` exists because of exactly this: a shell script
  with CRLF fails inside the container with *"bad interpreter"*.
- Creating the symlink in section 3 needs Developer Mode switched on, or an elevated prompt.

In exchange the files stay where Explorer, a file-based backup and any non-WSL tooling can see
them, which is the whole reason to choose it.

**Inside WSL 2.** A real Linux VM with its own ext4 disk — the same one the Docker daemon runs in,
so the container bind-mounts the project directly: `performance_mode: none`, no sync layer, no
ignore rules, no second copy. Symlinks, permissions and executable bits behave the way they do on
the production host, the checkout is LF like the repository, and shell scripts are run by Linux
rather than Git Bash. Everything below works unchanged; put the project under `~`, never under
`/mnt/c/`, or the translation layer is back with none of the benefits.

The cost: the files no longer sit on `C:` in any useful sense. Windows reaches them through
`\\wsl.localhost\Ubuntu\home\...`, which is slow and not a way to work, so the editor goes into the
distro too (section 8). And a file-based Windows backup sees the distro as one opaque `.vhdx` — fine
for a repository with a remote, not fine for anything else you might keep in the project folder.

---

## 2 · The directory layout

The repository *is* the plugin — its root contains `wp-racemanager.php` — and WordPress insists
that a plugin sits at `wp-content/plugins/<slug>/`. It has no dependency manager in the loading
path: plugins are found by *listing* that directory, a plugin's identity **is** the path string
(`active_plugins` holds `wp-racemanager/wp-racemanager.php`), and asset URLs are derived by
matching a file's real path against `WP_PLUGIN_DIR`. There is no `vendor/`-style indirection to
hang a working copy off, the way a Composer path repository does for a Symfony bundle.

Taken literally that buries the repository four levels down. So the site and the repository are
kept side by side, and a symlink inside the site points back at the repository:

```
WP_RaceManager/                     <- the DDEV project. Not a repository.
├── .ddev/
│   └── config.yaml                 <- docroot: wp-app
├── wp-racemanager/                 <- THIS repository. What you open in the editor.
└── wp-app/                         <- the whole site, and disposable
    ├── wp-admin/  wp-includes/     <- WordPress core, downloaded by WP-CLI
    ├── wp-config.php               <- the RM_VAPID_* constants go here
    └── wp-content/
        ├── uploads/                <- race JSON files live here
        └── plugins/
            └── wp-racemanager -> ../../../wp-racemanager
```

Two directories, one purpose each: the project directory *holds* an environment instead of
*being* one, `wp-app/` can be deleted and rebuilt without touching anything else, and the
repository is one level down where you can find it. Inside the container the plugin is at the
canonical path, and editing a file in the repository is editing the file the site loads.

### Why it is a *relative symlink*, specifically

Both halves of that carry weight, and neither is obvious.

**Symlink.** The instinct is that this cannot work, because PHP resolves `__FILE__` through the
link: inside the plugin, `plugin_dir_path( __FILE__ )` really does return
`/var/www/html/wp-racemanager/`, which is nowhere near `WP_PLUGIN_DIR`. Deriving a URL from that
would point outside `wp-content/plugins/`. WordPress has handled this since 3.9, though:
`wp-settings.php` calls `wp_register_plugin_realpath()` for every active plugin, which records the
`plugins/wp-racemanager` → real-path mapping in `$wp_plugin_paths`, and `plugin_basename()` walks
that mapping *backwards* before stripping `WP_PLUGIN_DIR`. So `plugin_dir_url()`, `plugins_url()`
and the block asset URLs that `register_block_type()` derives from `block.json` all come out under
`/wp-content/plugins/wp-racemanager/` regardless. Check it after any change to the layout:

```bash
ddev wp eval 'echo WP_RACEMANAGER_URL;'
```

It has to print the site URL plus `/wp-content/plugins/wp-racemanager/`. If it ever prints a path
containing `/wp-racemanager/` at the top level instead, the plugin is being loaded by something
other than WordPress's plugin loader and every asset URL on the site is wrong.

**Relative.** On Windows the project is synced into the container by Mutagen, which DDEV configures
with `symlink: mode: "portable"` (see `.ddev/mutagen/mutagen.yml`). Portable mode carries only
symlinks that are relative *and* resolve to somewhere inside the synchronization root — the project
directory. `../../../wp-racemanager` resolves to `<project>/wp-racemanager` and qualifies. An
absolute target does not, and the way it fails is worth knowing because nothing announces it:
Mutagen keeps syncing everything else and simply never creates that one link in the container. It
is reported only under *Scan problems* in

```bash
ddev mutagen status -l
```

as `invalid symbolic link: ... (absolute or unsupported path)`. So the symptom is a plugin that has
silently stopped existing inside the container while the host looks perfectly fine. This is why
`mklink` in section 3 is given a relative target even though an absolute one is easier to type.

Three things depend on this layout, so keep them:

- The link must be named **`wp-racemanager`**. It is the name the deployment ZIP carries,
  and — the reason it actually matters — the name a production database expects: `active_plugins`
  holds `wp-racemanager/wp-racemanager.php`, so under any other name an imported site treats the
  plugin as deactivated.
- `wp-app/` must stay disposable. Nothing of yours lives there; deleting it and re-running
  `bin/bootstrap-devenv.sh` has to stay a five-minute operation. The symlink lives inside
  `wp-app/`, so deleting the directory takes the link with it — recreate it (section 3) before
  running the bootstrap.
- `php tests/run.php` finds the WordPress checkout it needs for the `live-links` suite by looking
  three levels above the plugin — the layout where the repository really does sit inside the site —
  and then for a sibling `wp-app/`, which is this one. Either way the suite runs with no further
  configuration, and `WP_CORE_DIR` overrides both. See [`tests/README.md`](../tests/README.md).

---

## 3 · Create the site

Decide where the project lives first (section 1). The commands below are the same either way;
inside WSL 2 they run in the distro, under `~`, never under `/mnt/c/`.

Create the project directory, clone the repository beside the site, and let DDEV configure it.
`--project-name` decides the URL, so this becomes `https://racemanager.ddev.site`:

```bash
# Git Bash on Windows resolves ~ to C:\Users\<you>, WSL 2 and macOS to the home directory.
PROJECT=~/Dev/WP_RaceManager
mkdir -p "$PROJECT/wp-app/wp-content/plugins" && cd "$PROJECT"

git clone https://github.com/PSi86/wp-racemanager.git wp-racemanager

ddev config --project-name=racemanager --project-type=wordpress \
            --docroot=wp-app --php-version=8.3 --nodejs-version=20
```

`--php-version=8.3` is not cosmetic: `minishlink/web-push` v9 pulls in `web-token/jwt-library`,
which requires **PHP ≥ 8.2**. Push notifications silently stay unavailable on anything older.

Performance mode is deliberately not passed. DDEV picks it per platform — Mutagen on Windows and
macOS, none on Linux and inside WSL 2 — and `ddev describe` prints what it settled on under
*Perf mode*. Override it only if that is wrong for where your project actually sits.

Then the symlink that puts the repository where WordPress looks for it. It has to be **relative**,
and pointing at `../../../wp-racemanager` — section 2 explains why:

```bash
# Linux, macOS, WSL 2
ln -s ../../../wp-racemanager wp-app/wp-content/plugins/wp-racemanager
```

```bat
:: Windows, from a cmd prompt, in the project directory.
:: Needs Developer Mode switched on, or an elevated prompt.
mklink /D "wp-app\wp-content\plugins\wp-racemanager" "..\..\..\wp-racemanager"
```

Do not use `ln -s` from Git Bash on Windows: MSYS copies the directory instead of linking it
unless `MSYS=winsymlinks:nativestrict` is set, and a copy silently stops tracking your edits.
The target is relative to the **link's own directory**, not to the shell's, which is why it is
three levels up and not one.

Then start:

```bash
ddev start          # asks for elevation once, to add racemanager.ddev.site to the Windows hosts file
```

The `mkdir` above matters only because the link has to be created *inside*
`wp-app/wp-content/plugins/`, so that directory has to exist first. If WP-CLI later fails to
unpack a theme with *"Could not create directory"*, `wp-content` is not writable by the container
user — `bin/bootstrap-devenv.sh` checks for that before it does anything else.

### HTTPS, once

DDEV serves the site over HTTPS with a certificate issued by mkcert, and the browser has to trust
mkcert's certificate authority. On plain HTTP the service worker, the PWA install prompt and
`PushManager.subscribe()` are all unavailable, which is half of this plugin, so this is not
optional — section 6 says the same thing from the testing side.

```powershell
# Windows PowerShell. mkcert comes with DDEV, in %LOCALAPPDATA%\Programs\DDEV.
mkcert -install     # confirm the certificate dialog that appears
```

On macOS and Linux `mkcert -install` does the same thing; DDEV runs it for you on first start and
only asks if it cannot.

#### If, and only if, the project is inside WSL 2

The browser that has to trust the certificate runs on Windows while DDEV runs in the distro, so
both sides need the *same* certificate authority. Create it on Windows as above, then hand the
path across:

```powershell
setx CAROOT "$(mkcert -CAROOT)"
setx WSLENV "CAROOT/up"                # append with a ':' if WSLENV already has a value
```

`CAROOT/up` tells WSL to pass `CAROOT` through and rewrite it as a Unix path, so the distro reads
the same `rootCA.pem` from `/mnt/c/...`. In a **new** shell in the distro, so that it inherits the
variables:

```bash
echo "$CAROOT"          # must print /mnt/c/Users/<you>/AppData/Local/mkcert
sudo mkcert -install    # the distro's own trust store, so curl verifies too
ddev restart
```

`ddev restart` prints the URL it settled on. Without the shared CA it warns that the CA files are
unreadable and falls back to `http://racemanager.ddev.site`.

### Build the site

Everything after that is mechanical and checkable, so a script does it:

```bash
cd wp-racemanager
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
LIVE=$(ddev wp post create --post_type=page --post_title='Select Race' --post_name=live \
        --post_status=publish --porcelain)

# slug:shortcode -- the slugs match production, and "next-up" is the one where
# the two differ, because the shortcode has always been [rm_nextup].
for pair in bracket:rm_bracket pilots:rm_pilots stats:rm_stats next-up:rm_nextup; do
  ddev wp post create --post_type=page --post_name="${pair%%:*}" --post_title="${pair%%:*}" \
      --post_parent="$LIVE" --post_status=publish --post_content="[${pair##*:}]" --porcelain
done

ddev wp option update rm_live_page_id "$LIVE"
ddev wp rewrite flush
```

The slugs are not cosmetic: they are what the rewrite rule is built from, so using production's
is what makes a local URL and a production URL the same string. `bin/bootstrap-devenv.sh` uses
these, and renames an older `nextup` page to `next-up` rather than creating a second one beside
it.

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

npm ci                     # once, and after any pull that moves package-lock.json
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
| `ddev exec ls /var/www/html` | Note `MSYS_NO_PATHCONV=1` in Git Bash, or the path is rewritten before ddev.exe sees it. |
| `ddev delete -O` | Throw the database and the DDEV project away. The files stay, so to start truly fresh delete `wp-app/` too, recreate the plugin symlink (section 3), then `ddev start` and re-run `bin/bootstrap-devenv.sh`. The repository is outside `wp-app/`, so there is nothing to rescue first. |

### Scripting against ddev

Two things bite when a shell script drives `ddev` rather than a person:

- **`ddev` reads stdin.** Inside a `while read ... done < list` loop it consumes the rest of the
  list, so the loop body runs exactly once and the script looks like it silently skipped
  everything. Redirect: `ddev wp ... </dev/null`. A `for x in $list` loop does not have the
  problem, which is why `bin/bootstrap-devenv.sh` uses one.
- **Uploads are bind-mounted, not synced.** `.ddev/mutagen/mutagen.yml` ignores
  `/wp-app/wp-content/uploads`, but DDEV bind-mounts that path into the container separately, so a
  file dropped there on the host *is* immediately visible inside — no `ddev start`, no sync wait.
  That is where the per-race JSON lives, which makes importing production data a plain `cp`.

### Building the blocks

Only `race-gallery` is built from source. The other blocks are hand-written `index.js` files in
`blocks/` and need no toolchain at all.

The build runs **on the host**, not in the container: `@wordpress/scripts` takes
`blocks-src/race-gallery/` through webpack into `blocks/race-gallery/`, and that output is
committed. Changing the source means committing the rebuilt files with it.

```bash
npm ci                     # once, and after any pull that moves package-lock.json
npm run build              # blocks-src/ -> blocks/
npm run start              # watch mode while working on the block
```

`npm ci` rather than `npm install`, and the difference is not cosmetic here. `npm ci` installs
exactly what `package-lock.json` pins and fails loudly when the lock and `package.json` disagree;
`npm install` quietly rewrites the lock to make the disagreement go away. Since the built file is
committed, two people whose installs differ produce different `blocks/race-gallery/index.js` and
the diff churns for no reason. The toolchain asks for Node ≥ 18.12; the local setup and
`.devcontainer/devcontainer.json` both run 22.

#### `webpack.config.js` is what keeps the other six blocks alive

`blocks/` is the build output directory *and* the home of the six blocks that are hand-written
rather than built. wp-scripts empties its output directory before every emit, so left alone a
build deletes them — no error, just twelve files gone from `git status`. The config narrows the
clean to the folders that actually have a source, deriving the list from the directories under
`blocks-src/` so that adding a second source block needs no change to it.

That protection is version-specific and has already had to be rewritten once: up to
`@wordpress/scripts` 33 the cleaning was a `CleanWebpackPlugin` instance the config replaced,
and since 34 it is webpack's own `output.clean`, configured with a `keep` predicate. If a future
major moves it again, the symptom will be deleted files rather than a failing build. The check
that matters after any toolchain bump is therefore simply:

```bash
npm run build && git status --short blocks/
```

Nothing may show up as deleted. Only `blocks/race-gallery/` may show up as modified.

#### Keep node_modules out of the Mutagen sync

`node_modules/` is around 570 MB across a thousand top-level packages, it sits inside the DDEV
project directory, and the container never reads a byte of it — the site loads the *built* files
from `blocks/`. Left alone, Mutagen dutifully synchronises all of it into the container. Add the
path to the ignore list in `.ddev/mutagen/mutagen.yml`:

```yaml
sync:
  defaults:
    ignore:
      paths:
        - "/wp-racemanager/node_modules"
```

That file is normally DDEV's, so two more things are needed, and the second one is a trap:

1. Delete the `#ddev-generated` line at the top. Otherwise DDEV rewrites the file from its
   template and the ignore is gone.
2. **Do not write that marker anywhere else in the file — not even inside a comment explaining
   why you removed it.** DDEV searches the entire file for the string, so such a comment hands the
   file straight back to DDEV, and the ignore vanishes at the next `ddev start`. Nothing reports
   it; the first symptom is 570 MB reappearing in the container.
3. `ddev mutagen reset && ddev start`.

Check it with `ddev exec ls /var/www/html/wp-racemanager`, which should no longer list
`node_modules`. Ignoring a path means Mutagen leaves it alone on *both* sides, so the copy npm
just installed on the host stays exactly where it is.

`.ddev/` is not part of this repository, so this is a per-machine step, like the symlink in
section 3.

`.devcontainer/devcontainer.json` still describes the GitHub Codespace the blocks used to be
built in — PHP 8.2 plus Node 18. It still satisfies the toolchain, but Node 18 is past end of
life, and the local build above removes the reason to go through it.

---

## 8 · VS Code and Claude Code

Open **the repository** (`<project>/wp-racemanager`) as the workspace root, not the project
directory and not the WordPress root. `CLAUDE.md`, `docs/` and `tests/` sit there, and Claude Code
picks up `CLAUDE.md` from the workspace root automatically.

One consequence of opening the repository rather than the project: `ddev` has to be run from the
project directory, because it finds its project by walking *up* from the working directory looking
for `.ddev/config.yaml`. From the repository that walk goes to the parent of the project and
misses it, so an integrated terminal that starts in the workspace root answers every `ddev`
command with *"could not find a project"*. `cd ..` first, or keep a second terminal there.

Recommended extensions: PHP Intelephense, PHP Debug (Xdebug), EditorConfig.

**If the project is inside WSL 2**, connect VS Code into the distro with the **WSL** extension
(`ms-vscode-remote.remote-wsl`): `code .` from a shell in the repository, or the `><` button at the
bottom left → *Connect to WSL*. The title bar then reads `[WSL: Ubuntu]`. VS Code splits itself in
two — the window stays a Windows process, while a server installed into the distro runs everything
that touches files or processes: the explorer, search, the integrated terminal, git, the debugger
and the language servers. Extensions are classified along the same line, so PHP Intelephense, PHP
Debug and the Claude Code extension each need one click on *Install in WSL: Ubuntu* the first time.
The integrated terminal is then a Linux shell, which is where `ddev`, `composer` and `bin/*.sh`
are supposed to run anyway.

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
