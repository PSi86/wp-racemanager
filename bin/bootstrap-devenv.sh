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
# It expects the layout described in docs/development-setup.md, with WordPress
# below the docroot rather than at the project root:
#
#   <ddev project>/
#   |- .ddev/config.yaml         docroot: wp-app
#   `- wp-app/                   <- WP_APP_DIR below
#      `- wp-content/plugins/wp-racemanager/     <- this repository
#
# The one-time host steps that have to happen before this script can run:
#
#   mkdir wp-app && ddev config --project-type=wordpress --docroot=wp-app \
#       --php-version=8.3 && ddev start
#
# Set WP_APP_DIR to match if the docroot is named differently.
#
# See docs/development-setup.md for what each step is for, and run
# bin/dev-doctor.sh afterwards to compare the result against production.

set -euo pipefail

WP_APP_DIR="${WP_APP_DIR:-wp-app}"
PLUGIN_DIR="/var/www/html/${WP_APP_DIR}/wp-content/plugins/wp-racemanager"
RECREATE_LIVE_PAGES=0

# The live area: one parent page, one child per view, each holding its shortcode.
# The child slugs are what the /live/{race}/{view}/ rewrite rule is built from.
LIVE_VIEWS="bracket pilots stats nextup"

for arg in "$@"; do
    case "$arg" in
        --recreate-live-pages) RECREATE_LIVE_PAGES=1 ;;
        -h|--help) sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
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
        Move this repository to <ddev project>/${WP_APP_DIR}/wp-content/plugins/wp-racemanager,
        or set WP_APP_DIR if the docroot is named differently."
ok "plugin found at $PLUGIN_DIR"

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
if [ "$(wp eval 'echo wp_is_block_theme() ? 1 : 0;' 2>/dev/null | tr -d '\r\n')" = "1" ]; then
    ok "active theme is a block theme: $(wp option get stylesheet 2>/dev/null)"
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
    LIVE_ID="$(wp post create --post_type=page --post_title='Live' --post_name=live \
        --post_status=publish --porcelain | tr -d '\r\n')"
    did "created the parent page /live/ (ID $LIVE_ID)"
fi

for view in $LIVE_VIEWS; do
    CHILD_ID="$(page_id "$view" "$LIVE_ID")"
    if [ -n "$CHILD_ID" ]; then
        ok "/live/$view/ exists (ID $CHILD_ID)"
    else
        CHILD_ID="$(wp post create --post_type=page --post_title="$view" --post_name="$view" \
            --post_parent="$LIVE_ID" --post_status=publish \
            --post_content="[rm_$view]" --porcelain | tr -d '\r\n')"
        did "created /live/$view/ with [rm_$view] (ID $CHILD_ID)"
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

The live pages hold only their shortcode. Add a navigation block to /live/ by
hand if you want to click between the four views.
EOF
