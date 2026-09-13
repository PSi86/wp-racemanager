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
# The Composer dependencies are added afterwards, because vendor/ is git-ignored:
# installed from the ref's composer.lock, or without composer copied from the
# working copy once they match it. The lock itself stays out of the ZIP.
#
# See docs/deployment.md.

set -euo pipefail

SLUG="wp-racemanager"
REF="HEAD"
WITH_VENDOR=1

for arg in "$@"; do
    case "$arg" in
        --no-vendor) WITH_VENDOR=0 ;;
        -h|--help)   sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
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
# git archive converts line endings as a checkout would: with core.autocrlf=true,
# the Windows default, every text file left as CRLF, and the same ref gave
# another artifact in Git Bash than in the DDEV container. Export the files as
# the repository holds them.
git -c core.autocrlf=false archive --format=tar --prefix="$SLUG/" "$REF" | tar -x -C "$STAGE"

find_python() {
    for candidate in python3 python; do
        if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -c 'import json, zipfile' >/dev/null 2>&1; then
            echo "$candidate"
            return
        fi
    done
}

# Git Bash normally rewrites POSIX paths on the way to a native executable,
# but MSYS_NO_PATHCONV=1 turns that off -- and the docs tell people to export
# it, because ddev needs it. So a Windows Python would be handed /c/Users/...
# and fail. Convert explicitly rather than depending on the shell's mood.
to_native() {
    if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else printf '%s' "$1"; fi
}

# composer.lock is tracked (0ddcc4e), so git archive has exported the one of
# $REF: the versions this build is to contain. A ref from before that has none.
LOCK="$STAGE/$SLUG/composer.lock"

if [ "$WITH_VENDOR" -eq 1 ]; then
    if command -v composer >/dev/null 2>&1; then
        # Install fresh rather than copying the working copy's vendor/: a local
        # install may hold packages checked out from source, .git directories and
        # all, which would multiply the artifact's size.
        if [ ! -f "$LOCK" ] && [ -f composer.lock ]; then
            cp composer.lock "$LOCK"
        fi
        echo "Installing Composer dependencies ..."
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
            --working-dir="$STAGE/$SLUG" >/dev/null
    elif [ -f vendor/autoload.php ]; then
        echo "No composer -- copying the existing vendor/ ..."
        cp -R vendor "$STAGE/$SLUG/vendor"
        # That vendor/ is whatever was last installed in this working copy. Ship
        # it only when it holds exactly the packages $REF's lock names: a set that
        # differs can break push without failing a single test (web-push 11
        # resolves its HTTP client at runtime).
        PY="$(find_python)"
        if [ ! -f "$LOCK" ]; then
            echo "  $REF has no composer.lock -- vendor/ shipped unchecked." >&2
        elif [ -z "$PY" ]; then
            echo "  no Python to read composer.lock -- vendor/ shipped unchecked." >&2
        else
            "$PY" - "$(to_native "$LOCK")" "$(to_native "$STAGE/$SLUG/vendor/composer/installed.json")" <<'PYLOCK' || exit 1
import json, sys

lock_path, installed_path = sys.argv[1], sys.argv[2]
with open(lock_path, encoding="utf-8") as f:
    lock = {p["name"]: p["version"] for p in json.load(f)["packages"]}
try:
    with open(installed_path, encoding="utf-8") as f:
        data = json.load(f)
except OSError:
    sys.exit("FAILED: vendor/ has no composer/installed.json to check against composer.lock.")
packages = data["packages"] if isinstance(data, dict) else data  # Composer 2, else 1
installed = {p["name"]: p["version"] for p in packages}

differ = sorted(
    "%s: lock %s, vendor/ %s" % (name, lock.get(name, "-"), installed.get(name, "-"))
    for name in set(lock) | set(installed)
    if lock.get(name) != installed.get(name)
)
if differ:
    sys.exit(
        "FAILED: vendor/ is not what composer.lock names:\n  " + "\n  ".join(differ)
        + "\nRun composer install here, or build where composer is (the DDEV container)."
    )
print("  vendor/ holds the %d packages composer.lock names" % len(lock))
PYLOCK
        fi
    else
        echo "No composer and no vendor/ -- building without the push library." >&2
        echo "Push notifications will be unavailable until it is installed." >&2
    fi
fi

# The lock is what the build installs from, not part of the plugin: it goes
# whichever way vendor/ came, and with --no-vendor too.
rm -f "$LOCK"

# Packages can arrive as git checkouts -- Composer falls back to a source install
# whenever a dist download is unavailable, and a copied vendor/ inherits whatever
# the working copy has. Their .git directories are both the bulk of the artifact's
# size and repository data that has no place on a web server.
if [ -d "$STAGE/$SLUG/vendor" ]; then
    find "$STAGE/$SLUG/vendor" \( -name .git -o -name .github \) -prune -exec rm -rf {} +
fi

# Git Bash on Windows ships neither zip nor unzip, and that is where this is
# built now, so fall back to Python's zipfile rather than failing at the last
# step. Entry paths must use forward slashes and there must be exactly one
# top-level directory -- WordPress rejects an archive shaped any other way -- so
# the result is checked below instead of trusted.
echo "Zipping ..."
if command -v zip >/dev/null 2>&1; then
    ( cd "$STAGE" && zip -qr9 - "$SLUG" ) > "$ZIP"
else
    PY="$(find_python)"
    [ -n "$PY" ] || {
        echo "Neither zip nor a usable Python found -- cannot build the archive." >&2
        echo "Install zip (Linux/WSL: apt install zip), or a Python with zipfile." >&2
        exit 1
    }
    echo "  no zip command -- using $PY"
    "$PY" - "$(to_native "$STAGE")" "$SLUG" "$(to_native "$(pwd)/$ZIP")" <<'PYZIP'
import os, sys, zipfile

stage, slug, target = sys.argv[1], sys.argv[2], sys.argv[3]
root = os.path.join(stage, slug)

with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames.sort()
        for name in sorted(filenames):
            full = os.path.join(dirpath, name)
            # Relative to the staging directory, so the archive carries the
            # single wp-racemanager/ prefix WordPress expects.
            rel = os.path.relpath(full, stage).replace(os.sep, "/")
            z.write(full, rel)
PYZIP
fi

[ -s "$ZIP" ] || { echo "The archive was not created." >&2; exit 1; }

echo
echo "$ZIP"

# Report, and verify the shape, without depending on unzip either.
if command -v unzip >/dev/null 2>&1; then
    unzip -l "$ZIP" | tail -1
elif [ -n "${PY:-}" ]; then
    "$PY" - "$(to_native "$(pwd)/$ZIP")" "$SLUG" <<'PYCHECK'
import sys, zipfile

path, slug = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(path) as z:
    names = z.namelist()
    total = sum(i.file_size for i in z.infolist())

roots = {n.split("/", 1)[0] for n in names}
if roots != {slug}:
    sys.exit("FAILED: archive has top-level entries %s, expected only %r" % (sorted(roots), slug))
if any("\\" in n for n in names):
    sys.exit("FAILED: archive contains backslashes in entry paths")

print("  %d files, %.1f MB uncompressed, single top-level %s/" % (len(names), total / 1048576.0, slug))
PYCHECK
fi
