#!/usr/bin/env bash
#
# Build the deployable plugin ZIP.
#
#   bin/build-plugin-zip.sh [ref] [--no-vendor]
#
# Produces build/wp-racemanager-<ref>.zip with a single top-level folder
# "wp-racemanager/", which is what WordPress expects from an uploaded plugin ZIP.
#
# Only tracked files are exported, minus everything marked export-ignore in
# .gitattributes -- so tests/, docs/, blocks-src/ and the npm files stay out.
# The Composer dependencies are added afterwards, because vendor/ is git-ignored.
#
# See docs/deployment.md.

set -euo pipefail

SLUG="wp-racemanager"
REF="HEAD"
WITH_VENDOR=1

for arg in "$@"; do
    case "$arg" in
        --no-vendor) WITH_VENDOR=0 ;;
        -h|--help)   sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*)          echo "Unknown option: $arg" >&2; exit 1 ;;
        *)           REF="$arg" ;;
    esac
done

cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

git rev-parse --verify --quiet "$REF^{commit}" >/dev/null || {
    echo "Not a valid ref: $REF" >&2
    exit 1
}

if ! git diff --quiet HEAD -- ':!build' 2>/dev/null; then
    echo "Note: the working tree has uncommitted changes; they are NOT in the archive." >&2
fi

VERSION="$(git rev-parse --short "$REF")"
[ "$REF" = "HEAD" ] || VERSION="$(echo "$REF" | tr '/' '-')"

BUILD_DIR="build"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$BUILD_DIR"
ZIP="$BUILD_DIR/$SLUG-$VERSION.zip"
rm -f "$ZIP"

echo "Exporting $REF ..."
git archive --format=tar --prefix="$SLUG/" "$REF" | tar -x -C "$STAGE"

if [ "$WITH_VENDOR" -eq 1 ]; then
    if command -v composer >/dev/null 2>&1; then
        # Install fresh rather than copying the working copy's vendor/: a local
        # install may hold packages checked out from source, .git directories and
        # all, which would multiply the artifact's size.
        if [ -f composer.lock ]; then
            cp composer.lock "$STAGE/$SLUG/composer.lock"
        fi
        echo "Installing Composer dependencies ..."
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
            --working-dir="$STAGE/$SLUG" >/dev/null
        rm -f "$STAGE/$SLUG/composer.lock"
    elif [ -f vendor/autoload.php ]; then
        echo "No composer -- copying the existing vendor/ ..."
        cp -R vendor "$STAGE/$SLUG/vendor"
    else
        echo "No composer and no vendor/ -- building without the push library." >&2
        echo "Push notifications will be unavailable until it is installed." >&2
    fi
fi

# Packages can arrive as git checkouts -- Composer falls back to a source install
# whenever a dist download is unavailable, and a copied vendor/ inherits whatever
# the working copy has. Their .git directories are both the bulk of the artifact's
# size and repository data that has no place on a web server.
if [ -d "$STAGE/$SLUG/vendor" ]; then
    find "$STAGE/$SLUG/vendor" \( -name .git -o -name .github \) -prune -exec rm -rf {} +
fi

echo "Zipping ..."
( cd "$STAGE" && zip -qr9 - "$SLUG" ) > "$ZIP"

echo
echo "$ZIP"
unzip -l "$ZIP" | tail -1
