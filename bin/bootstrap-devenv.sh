#!/usr/bin/env bash
#
# Bring a local DDEV site to the state this plugin needs to run.
#
#   bin/bootstrap-devenv.sh              set up everything that is missing
#   bin/bootstrap-devenv.sh --recreate-live-pages
#                                        delete and rebuild the /live/ pages
#
# Run it from anywhere inside the DDEV project, with the project started.
# Everything goes through `ddev wp`, so it reads the site's real state rather
# than assuming a fresh install -- every step is skipped when already done, and
# running it twice changes nothing.
#
# It expects the layout described in docs/development-setup.md: the repository
# sits beside the site and a relative symlink inside wp-content/plugins/ points
# back at it, so inside the container the plugin is at the path WordPress insists
# on without being buried four levels deep on disk.
#
#   <ddev project>/
#   |- .ddev/config.yaml                   docroot: wp-app
#   |- wp-racemanager/                     <- this repository
#   `- wp-app/                             <- WP_APP_DIR below, disposable
#      `- wp-content/plugins/
#         `- wp-racemanager -> ../../../wp-racemanager
#
# The symlink target has to be relative and has to stay inside the project, because
# on Windows Mutagen carries it into the container in "portable" mode, which refuses
# anything else. See section 2 of docs/development-setup.md.
#
# The one-time host steps that have to happen before this script can run -- ddev
# config, the symlink and ddev start -- are in section 3 of
# docs/development-setup.md.
#
# Set WP_APP_DIR to match if the docroot is named differently.
#
# See docs/development-setup.md for what each step is for, and run
# bin/dev-doctor.sh afterwards to compare the result against production.

set -euo pipefail

# Git Bash on Windows rewrites arguments that look like absolute POSIX paths
# into Windows paths before handing them to a native executable, so ddev.exe
# would receive C:/Program Files/Git/var/www/html/... instead of the container
# path. Harmless environment variables everywhere else.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

WP_APP_DIR="${WP_APP_DIR:-wp-app}"
PLUGIN_DIR="/var/www/html/${WP_APP_DIR}/wp-content/plugins/wp-racemanager"
RECREATE_LIVE_PAGES=0

# The live area: one parent page, one child per view, each holding its shortcode.
# The child slugs are what the /live/{race}/{view}/ rewrite rule is built from, so
# they are also what makes a local URL identical to production's. These match
# copterrace.com -- note "next-up", whose shortcode is nevertheless [rm_nextup].
LIVE_VIEWS="bracket pilots stats next-up"

# The slug is not always the shortcode name, and not always the page title.
view_shortcode() {
    case "$1" in
        next-up) printf 'rm_nextup' ;;
        *)       printf 'rm_%s' "$1" ;;
    esac
}

view_title() {
    case "$1" in
        next-up) printf 'Next up' ;;
        *)       printf '%s' "$1" | awk '{ print toupper(substr($0,1,1)) substr($0,2) }' ;;
    esac
}

for arg in "$@"; do
    case "$arg" in
        --recreate-live-pages) RECREATE_LIVE_PAGES=1 ;;
        -h|--help) awk 'NR > 1 { if (!/^#/) exit; sub(/^# ?/, ""); print }' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
ok()   { printf '  \033[32mok\033[0m    %s\n' "$1"; }
did()  { printf '  \033[36mdone\033[0m  %s\n' "$1"; }
warn() { printf '  \033[33mwarn\033[0m  %s\n' "$1"; }
die()  { printf '  \033[31mFAIL\033[0m  %s\n' "$1" >&2; exit 1; }

wp() { ddev wp "$@"; }

# Page id by slug, optionally restricted to a parent. Reading the whole list and
# filtering here avoids WP_Query's special handling of the 'name' argument for
# hierarchical post types, which does not match child pages by slug alone.
page_id() {
    ddev wp post list --post_type=page --post_status=any \
        --fields=ID,post_name,post_parent --format=csv 2>/dev/null |
        awk -F, -v slug="$1" -v parent="${2:-}" \
            'NR > 1 && $2 == slug && (parent == "" || $3 == parent) { print $1; exit }'
}

# ---------------------------------------------------------------- preconditions
step "Preconditions"

command -v ddev >/dev/null 2>&1 || die "ddev not found in PATH."
ddev describe >/dev/null 2>&1 || die "Not inside a started DDEV project. Run 'ddev start' first."
ok "ddev is available and the project is running"

ddev exec test -f "$PLUGIN_DIR/wp-racemanager.php" 2>/dev/null \
    || die "The plugin is not at $PLUGIN_DIR inside the container.
        Check that ${WP_APP_DIR}/wp-content/plugins/wp-racemanager is a symlink to
        ../../../wp-racemanager and that 'ddev start' has synced it, or set WP_APP_DIR
        if the docroot is named differently. An absolute symlink target is the usual
        cause -- Mutagen's 'portable' mode refuses to carry one. See section 3 of
        docs/development-setup.md."
ok "plugin found at $PLUGIN_DIR"

# WP-CLI unpacks core, the theme and Contact Form 7 into wp-content/, so nothing
# below is worth trying until the container user can write there.
if ddev exec test -w "/var/www/html/${WP_APP_DIR}/wp-content" 2>/dev/null; then
    ok "wp-content is writable"
else
    die "/var/www/html/${WP_APP_DIR}/wp-content is not writable in the container.
        Repair it on the host with 'sudo chown -R \$USER: ${WP_APP_DIR}' and run
        'ddev start' again."
fi

PHP_VERSION="$(ddev exec php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || true)"
if [ -n "$PHP_VERSION" ] && [ "$(printf '%s\n8.2\n' "$PHP_VERSION" | sort -V | head -1)" = "8.2" ]; then
    ok "PHP $PHP_VERSION"
else
    die "PHP ${PHP_VERSION:-unknown} -- web-token/jwt-library needs 8.2 or newer.
        Set php_version in .ddev/config.yaml and run 'ddev restart'."
fi

# ------------------------------------------------------------------- WordPress
step "WordPress core"

if ddev exec test -f "/var/www/html/${WP_APP_DIR}/wp-includes/version.php" 2>/dev/null; then
    ok "core is present"
else
    wp core download
    did "downloaded core"
fi

if wp core is-installed 2>/dev/null; then
    ok "site is installed: $(wp option get siteurl 2>/dev/null)"
else
    SITE_URL="$(ddev exec printenv DDEV_PRIMARY_URL | tr -d '\r\n')"
    wp core install \
        --url="$SITE_URL" \
        --title='RaceManager Dev' \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email='dev@example.test' \
        --skip-email
    did "installed WordPress at $SITE_URL (admin / admin)"
fi

step "Archiving by itself"

# The plugin archives a live race a day after its end (1.9.0). The races this site
# imports from production are months old, and the browser suites need one of them
# live: it would be archived within the hour, and again after every reset. Set
# before the plugin is active, so that no page ever schedules it.
if wp config has RM_AUTO_ARCHIVE --type=constant 2>/dev/null; then
    ok "RM_AUTO_ARCHIVE is set in wp-config.php"
else
    wp config set RM_AUTO_ARCHIVE false --raw --type=constant
    did "set RM_AUTO_ARCHIVE to false in wp-config.php -- races stay live until archived by hand"
fi

# ----------------------------------------------------------------- the plugin
step "Plugin dependencies"

if ddev exec test -d "$PLUGIN_DIR/vendor" 2>/dev/null; then
    ok "vendor/ is present"
else
    ddev exec -d "$PLUGIN_DIR" composer install --no-interaction --no-progress
    did "installed the Composer dependencies"
fi

step "Theme"

# rm_print_js_module_config() is hooked to wp_head from inside the live
# shortcodes, which only works under a block theme -- a classic theme renders
# the template after wp_head() has already run, so the live pages would silently
# lose their JavaScript configuration. See docs/wordpress-update-audit.md (B3).
#
# Asking wp_is_block_theme() through `wp eval` answers wrongly and prints a
# "called incorrectly" notice under WordPress 6.8: WP-CLI has not registered the
# theme directory at that point. The theme was reinstalled on every run because
# of it. A block theme is defined by carrying templates/index.html, so look for
# the file instead.
STYLESHEET="$(wp option get stylesheet 2>/dev/null | tr -d '\r\n')"
THEME_DIR="/var/www/html/${WP_APP_DIR}/wp-content/themes/${STYLESHEET}"
if [ -n "$STYLESHEET" ] && { ddev exec test -f "$THEME_DIR/templates/index.html" 2>/dev/null \
        || ddev exec test -f "$THEME_DIR/block-templates/index.html" 2>/dev/null; }; then
    ok "active theme is a block theme: $STYLESHEET"
else
    wp theme install twentytwentyfive --activate
    did "installed and activated Twenty Twenty-Five"
fi

step "Contact Form 7"

# The activation hook deactivates the plugin and dies unless CF7 is already
# active, so this has to come first.
if wp plugin is-active contact-form-7 2>/dev/null; then
    ok "active"
else
    wp plugin install contact-form-7 --activate
    did "installed and activated"
fi

step "WP RaceManager"

if wp plugin is-active wp-racemanager 2>/dev/null; then
    ok "active"
else
    wp plugin activate wp-racemanager
    did "activated -- this created the database tables, the CF7 registration form,
        pwa-sw.js, manifest.json and a VAPID key pair"
fi

step "Permalinks"

PERMALINK="$(wp option get permalink_structure 2>/dev/null || true)"
if [ -n "$PERMALINK" ]; then
    ok "structure: $PERMALINK"
else
    wp rewrite structure '/%postname%/'
    did "switched to /%postname%/ -- /live/{race}/{view}/ cannot work on plain permalinks"
fi

# ------------------------------------------------------------------ live pages
step "Live area"

if [ "$RECREATE_LIVE_PAGES" = "1" ]; then
    EXISTING="$(page_id live)"
    if [ -n "$EXISTING" ]; then
        for view in $LIVE_VIEWS; do
            CHILD="$(page_id "$view" "$EXISTING")"
            [ -n "$CHILD" ] && wp post delete "$CHILD" --force >/dev/null
        done
        wp post delete "$EXISTING" --force >/dev/null
        did "deleted the previous live pages"
    fi
fi

LIVE_ID="$(page_id live)"
if [ -n "$LIVE_ID" ]; then
    ok "parent page /live/ exists (ID $LIVE_ID)"
else
    LIVE_ID="$(wp post create --post_type=page --post_title='Select Race' --post_name=live \
        --post_status=publish --porcelain | tr -d '\r\n')"
    did "created the parent page /live/ (ID $LIVE_ID)"
fi

# Older installs built by this script have "nextup"; production has "next-up".
# Renaming beats creating a second page, which is what the create below would
# otherwise do -- and the slug is baked into the rewrite rule, so leaving both
# would give the live area a view that no longer matches production.
LEGACY_NEXTUP="$(page_id nextup "$LIVE_ID")"
if [ -n "$LEGACY_NEXTUP" ] && [ -z "$(page_id next-up "$LIVE_ID")" ]; then
    wp post update "$LEGACY_NEXTUP" --post_name=next-up --post_title='Next up' >/dev/null
    did "renamed /live/nextup/ to /live/next-up/ (ID $LEGACY_NEXTUP)"
fi

for view in $LIVE_VIEWS; do
    CHILD_ID="$(page_id "$view" "$LIVE_ID")"
    SHORTCODE="$(view_shortcode "$view")"
    if [ -n "$CHILD_ID" ]; then
        ok "/live/$view/ exists (ID $CHILD_ID)"
    else
        CHILD_ID="$(wp post create --post_type=page --post_title="$(view_title "$view")" \
            --post_name="$view" --post_parent="$LIVE_ID" --post_status=publish \
            --post_content="[$SHORTCODE]" --porcelain | tr -d '\r\n')"
        did "created /live/$view/ with [$SHORTCODE] (ID $CHILD_ID)"
    fi
done

if [ "$(wp option get rm_live_page_id 2>/dev/null | tr -d '\r\n')" = "$LIVE_ID" ]; then
    ok "rm_live_page_id points at $LIVE_ID"
else
    wp option update rm_live_page_id "$LIVE_ID" >/dev/null
    did "set rm_live_page_id to $LIVE_ID"
fi

# Updating rm_live_page_id rebuilds the rewrite rule, but the flush is what
# writes it to the cache that the front end reads.
wp rewrite flush >/dev/null
ok "rewrite rules flushed"

# ------------------------------------------------------------- live navigation
# Production's live pages carry a navigation with these six links, in this order.
# rm_rewrite_live_links() puts the race into them, rm_mark_live_navigation() marks
# the navigation for css/rm-live-nav.css -- neither can be seen working without
# one. A block theme's header navigation that names no menu of its own shows the
# most recently published wp_navigation, so creating this one is all Twenty
# Twenty-Five needs. Another theme may need it picked in the site editor.
step "Live navigation"

NAV_ID="$(wp post list --post_type=wp_navigation --post_status=publish --title='Live navigation' \
    --field=ID 2>/dev/null | head -n 1 | tr -d '\r\n')"
if [ -n "$NAV_ID" ]; then
    ok "the live navigation exists (ID $NAV_ID)"
else
    HOME_URL="$(wp option get home 2>/dev/null | tr -d '\r\n')"
    nav_link() { # label, url, page id
        printf '<!-- wp:navigation-link {"label":"%s","type":"page","id":%s,"url":"%s","kind":"post-type"} /-->' "$1" "$3" "$2"
    }
    NAV_CONTENT='<!-- wp:home-link {"label":"Home"} /-->'
    NAV_CONTENT+="$(nav_link 'Select Race' "$HOME_URL/live/" "$LIVE_ID")"
    for view in pilots bracket stats next-up; do
        NAV_CONTENT+="$(nav_link "$(view_title "$view")" "$HOME_URL/live/$view/" "$(page_id "$view" "$LIVE_ID")")"
    done
    NAV_ID="$(wp post create --post_type=wp_navigation --post_status=publish --post_title='Live navigation' \
        --post_content="$NAV_CONTENT" --porcelain | tr -d '\r\n')"
    did "created the live navigation (ID $NAV_ID): Home, Select Race, Pilots, Bracket, Stats, Next up"
fi

# --------------------------------------------------------------------- summary
step "Result"

VAPID_SOURCE="$(wp eval 'echo function_exists("rm_vapid_source") ? rm_vapid_source() : "unknown";' 2>/dev/null | tr -d '\r\n')"
case "$VAPID_SOURCE" in
    constant) ok "VAPID keys come from the RM_VAPID_* constants in wp-config.php" ;;
    option)   ok "VAPID keys are stored in the 'rm_vapid' option (generated on activation)" ;;
    none)     warn "no VAPID key pair -- generate one under Settings -> RaceManager" ;;
    *)        warn "could not determine the VAPID key source" ;;
esac

SITE_URL="$(wp option get siteurl 2>/dev/null | tr -d '\r\n')"
cat <<EOF

  Site       $SITE_URL  (admin / admin)
  Live area  $SITE_URL/live/

Next:
  bin/dev-doctor.sh     compare this site against what production runs
  ddev launch           open it
  ddev launch -m        Mailpit, where the registration mails land

The header shows the live navigation, as production's does; on a phone the race
pages add their own view tabs at the foot of the screen.
EOF
