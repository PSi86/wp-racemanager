#!/usr/bin/env bash
#
# Check a local development site against what WP RaceManager needs, and
# reconcile it with a production database that was imported into it.
#
#   bin/dev-doctor.sh              check only
#   bin/dev-doctor.sh --install    also install missing themes/plugins from wordpress.org
#
# Run it from anywhere inside the DDEV project. Everything goes through
# `ddev wp`, so it reads the site's real state rather than guessing.
#
# See docs/development-setup.md.

set -uo pipefail

INSTALL=0
[ "${1:-}" = "--install" ] && INSTALL=1

PASS=0; WARN=0; FAIL=0
ok()   { printf '  \033[32mok\033[0m    %s\n' "$1"; PASS=$((PASS+1)); }
warn() { printf '  \033[33mwarn\033[0m  %s\n' "$1"; WARN=$((WARN+1)); }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=$((FAIL+1)); }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

command -v ddev >/dev/null 2>&1 || { echo "ddev not found in PATH." >&2; exit 1; }
wp() { ddev wp "$@" 2>/dev/null; }

wp option get siteurl >/dev/null 2>&1 || {
    echo "Cannot talk to WordPress through ddev. Is the project started, and is WordPress installed?" >&2
    exit 1
}

# ---------------------------------------------------------------- environment
head_ "Environment"

PHP_VERSION="$(ddev exec php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)"
if [ -n "$PHP_VERSION" ] && [ "$(printf '%s\n8.2\n' "$PHP_VERSION" | sort -V | head -1)" = "8.2" ]; then
    ok "PHP $PHP_VERSION (>= 8.2, so the push library can load)"
else
    bad "PHP ${PHP_VERSION:-unknown} — web-token/jwt-library needs 8.2; set php_version in .ddev/config.yaml"
fi

PERMALINK="$(wp option get permalink_structure)"
if [ -n "$PERMALINK" ]; then
    ok "permalink structure: $PERMALINK"
else
    bad "plain permalinks — /live/{race}/{view}/ cannot work; wp rewrite structure '/%postname%/'"
fi

if wp eval 'echo is_ssl() ? "yes" : "no";' 2>/dev/null | grep -q yes || wp option get siteurl | grep -q '^https'; then
    ok "site runs over HTTPS (service worker and Web Push need a secure context)"
else
    warn "site is not on HTTPS — the PWA will not install and push will not subscribe"
fi

# ------------------------------------------------------------------ the theme
head_ "Theme"

TEMPLATE="$(wp option get template)"
STYLESHEET="$(wp option get stylesheet)"
if wp theme is-installed "$STYLESHEET"; then
    ok "active theme '$STYLESHEET' is installed"
elif [ "$INSTALL" = "1" ] && wp theme install "$STYLESHEET" >/dev/null 2>&1; then
    ok "installed theme '$STYLESHEET' from wordpress.org"
else
    bad "theme '$STYLESHEET' is missing — install it, or copy it from wp-content/themes/ on production"
fi

if wp eval 'echo wp_is_block_theme() ? "block" : "classic";' 2>/dev/null | grep -q block; then
    ok "'$TEMPLATE' is a block theme"
else
    warn "'$TEMPLATE' is a classic theme — the live pages print their JS config from inside a shortcode (B3) and only work in a block theme"
fi

# ---------------------------------------------------------------- the plugins
head_ "Plugins"

ACTIVE="$(wp option get active_plugins --format=json)"
# WP-CLI writes the option as JSON with escaped slashes: "wp-racemanager\/wp-racemanager.php"
SLUGS="$(printf '%s' "$ACTIVE" | tr ',' '\n' | sed -n 's|^[^"]*"\([^"\\/]*\)[\\/].*|\1|p' | sort -u)"

MISSING=""
for slug in $SLUGS; do
    if wp plugin is-installed "$slug"; then
        continue
    fi
    if [ "$INSTALL" = "1" ] && wp plugin install "$slug" >/dev/null 2>&1; then
        ok "installed '$slug' from wordpress.org"
    else
        MISSING="$MISSING $slug"
    fi
done

if [ -n "$MISSING" ]; then
    bad "active on production but not installed here:$MISSING"
    echo "        run with --install, or copy the folders from wp-content/plugins/ on production"
else
    ok "every plugin the database lists as active is installed"
fi

if wp plugin is-active contact-form-7; then
    ok "Contact Form 7 is active (the activation hook refuses to run without it)"
else
    bad "Contact Form 7 is not active"
fi

wp plugin is-active wp-racemanager && ok "wp-racemanager is active" || bad "wp-racemanager is not active"

# ------------------------------------------------------------- the live area
head_ "Live area"

LIVE_ID="$(wp option get rm_live_page_id)"
if [ -n "$LIVE_ID" ] && [ "$(wp post get "$LIVE_ID" --field=post_status)" = "publish" ]; then
    LIVE_SLUG="$(wp post get "$LIVE_ID" --field=post_name)"
    ok "live page: /$LIVE_SLUG/ (ID $LIVE_ID)"

    VIEWS="$(wp post list --post_type=page --post_parent="$LIVE_ID" --post_status=publish \
             --orderby=menu_order --order=ASC --field=post_name | tr '\n' ' ')"
    if [ -n "${VIEWS// /}" ]; then
        ok "views: $VIEWS"
    else
        bad "the live page has no published child pages, so there are no views and no rewrite rule"
    fi
else
    bad "rm_live_page_id is not set to a published page — Settings → RaceManager"
fi

wp option get rm_live_routing >/dev/null 2>&1 \
    && ok "rewrite cache present (rm_live_routing)" \
    || warn "no rm_live_routing option yet — re-save Settings → Permalinks"

# --------------------------------------------------------------------- push
head_ "Push"

if wp eval 'require_once WP_PLUGIN_DIR . "/wp-racemanager/includes/vapid-handler.php"; echo rm_push_library_available() ? "yes" : "no";' 2>/dev/null | grep -q yes; then
    ok "minishlink/web-push is loadable"
else
    bad "push library not found — run composer install in the plugin folder"
fi

PREFIX="$(wp db prefix 2>/dev/null | tr -d '[:space:]')"
SUBS="$(wp db query "SELECT COUNT(*) FROM ${PREFIX}rm_subscriptions;" --skip-column-names 2>/dev/null | tr -d '[:space:]')"
case "${SUBS:-}" in
    ''|*[!0-9]*) warn "subscriptions table not found — it is created on activation" ;;
    0)           ok "no push subscriptions stored" ;;
    *)           warn "$SUBS push subscription(s) in the database. After importing the production database these are REAL devices — clear the table before sending a test notification." ;;
esac

# --------------------------------------------------------------- generated files
head_ "Generated files"

for f in manifest.json pwa-sw.js; do
    if ddev exec test -f "/var/www/html/$f" >/dev/null 2>&1; then
        ok "$f exists in the WordPress root"
    else
        warn "$f missing — save Settings → RaceManager once to regenerate it"
    fi
done

# ------------------------------------------------------------------- summary
printf '\n%s\n' "----------------------------------------------------------"
printf '  %d ok, %d warning(s), %d failure(s)\n' "$PASS" "$WARN" "$FAIL"
[ "$FAIL" -eq 0 ]
