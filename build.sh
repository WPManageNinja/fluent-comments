#!/usr/bin/env bash
#
# Build a distributable FluentComments zip (the WordPress.org release).
#
#   ./build.sh                     production build
#   ./build.sh --pot               ...regenerate languages/fluent-comments.pot first
#   ./build.sh --skip-build        ...reuse whatever is in dist/ already
#   ./build.sh --to_s3             ...and upload the zip to S3
#   ./build.sh --out DIR           write the zip somewhere else
#
# The zip is assembled from an explicit list of paths rather than by excluding
# things, so a new top-level file or directory has to be added here on purpose
# before it can reach a user. Getting that backwards is how resources/, tests/
# and node_modules end up shipped.
#
# This is the plugin WordPress.org serves, so the version has to agree in three
# places (header, constant, readme Stable tag) and a mismatch is fatal here
# rather than a wrong-version rollout to every install.

set -euo pipefail

readonly ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SLUG="fluent-comments"
readonly MAIN_FILE="$ROOT/$SLUG.php"

OUT_DIR="$ROOT/builds"

nodeBuild=true
withPot=false
toS3=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --skip-build) nodeBuild=false; shift ;;
        --pot)        withPot=true; shift ;;
        --to_s3)      toS3=true; shift ;;
        --out)        OUT_DIR="$2"; shift 2 ;;
        -h|--help)    sed -n '2,18p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

# Resolved now, while the shell is still in the directory the user ran this
# from. A relative --out would otherwise be interpreted against wherever the
# script has cd'd to by the time the zip is written.
mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"

step() { printf '\n\033[1m==>\033[0m %s\n' "$1"; }
warn() { printf '\033[33m  %s\033[0m\n' "$1"; }
die()  { printf '\n\033[31mBuild failed:\033[0m %s\n' "$1" >&2; exit 1; }

# Everything the release contains. Anything not listed never reaches the zip.
readonly PAYLOAD=(
    app
    dist
    languages
    "$SLUG.php"
    index.php
    readme.txt
    uninstall.php
)

# resources/ is a source tree and is not shipped -- except for this one file,
# which register_block_type() reads at runtime by path. Listed separately so
# the "not on either list" report below can keep treating resources/ as a whole
# directory that stays behind.
readonly PAYLOAD_FILES=(
    resources/block/block.json
)

# Top-level entries that are deliberately not shipped. Only used to keep the
# report below quiet about things we already know about, so an entry here is a
# decision rather than an oversight. Anything else in the repo root gets named
# at build time -- including one-off audit files and scratch notes, which is
# the point.
readonly NOT_SHIPPED=(
    .claude .conductor .distignore .DS_Store .git .github .gitignore .gstack
    .idea .vscode
    builds node_modules resources svn tests
    CLAUDE.md README.md i18n.node.js package.json pnpm-lock.yaml
    build.sh svelte.config.js vite.config.js
)

# Build leftovers, editor droppings and OS metadata. Vite is configured with
# emptyOutDir:false (the block pipeline writes into the same dist/), and .pot
# editors leave backups, so both have shipped from plugins in this family
# before now.
readonly JUNK=(
    ".DS_Store" "__MACOSX" "._*" "Thumbs.db" "*~" "*.orig" "*.rej"
    ".gitignore" ".gitattributes" ".gitkeep"
    "*.map"
)

# ---------------------------------------------------------------- version ---

[[ -f "$MAIN_FILE" ]] || die "$MAIN_FILE not found. Run this from the plugin directory."

VERSION="$(grep -m1 -E '^[[:space:]]*\*?[[:space:]]*Version:' "$MAIN_FILE" \
    | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"

[[ -n "$VERSION" ]] || die "Could not read the Version header from $SLUG.php"

# The header, the constant and the readme are read by three different things --
# WordPress trusts the header, our own code compares the constant, and
# WordPress.org serves whatever Stable tag points at. A mismatch between any
# two is a plugin that reports a different version depending on who asks, or a
# release that ships the wrong tag to every install.
CONST_VERSION="$(grep -m1 "FLUENT_COMMENTS_VERSION'" "$MAIN_FILE" \
    | sed -E "s/.*',[[:space:]]*'([^']+)'.*/\1/")"

[[ "$CONST_VERSION" == "$VERSION" ]] \
    || die "Version header says $VERSION but FLUENT_COMMENTS_VERSION says $CONST_VERSION."

# `|| true` because of `set -e`: with no match, grep exits 1 and kills the
# whole command substitution before the comparison below ever runs, so a
# readme with no Stable tag line at all died silently with no message.
STABLE_TAG="$(grep -m1 -E '^[[:space:]]*Stable tag:' "$ROOT/readme.txt" \
    | sed -E 's/.*Stable tag:[[:space:]]*//' | tr -d '[:space:]' || true)"

[[ "$STABLE_TAG" == "$VERSION" ]] \
    || die "Version is $VERSION but readme.txt Stable tag is ${STABLE_TAG:-<missing>}."

# package.json is what npm and the build scripts report, and block.json's
# version is what register_block_type() hands the browser for cache busting
# on the editor assets -- a stale one ships quietly and the editor keeps
# loading yesterday's bundle. Neither is fatal to a site, which is exactly
# why they drift if nothing checks them.
check_json_version() {
    local file="$1" label="$2" found

    [[ -f "$ROOT/$file" ]] || die "$file not found."

    found="$(grep -m1 -E '^[[:space:]]*"version"[[:space:]]*:' "$ROOT/$file" \
        | sed -E 's/.*"version"[[:space:]]*:[[:space:]]*"([^"]*)".*/\1/' || true)"

    [[ "$found" == "$VERSION" ]] \
        || die "Version is $VERSION but $label says ${found:-<missing>}."
}

check_json_version "package.json" "package.json"
check_json_version "resources/block/block.json" "resources/block/block.json"

readonly VERSION
readonly STAGE="$OUT_DIR/.stage"
readonly ZIP="$OUT_DIR/$SLUG-$VERSION.zip"

step "Building $SLUG $VERSION"

# --------------------------------------------------------------- frontend ---

if "$nodeBuild"; then
    step "Building the frontend (Vite + Gutenberg block)"

    command -v npm >/dev/null || die "npm not found."
    [[ -d "$ROOT/node_modules" ]] || (cd "$ROOT" && npm install)

    # vite.config.js sets emptyOutDir:false, because the block's separate
    # webpack pipeline writes into the same dist/ and would be wiped by the
    # Vite build that runs before it. Nothing else clears the directory, so
    # Vite's content-hashed chunks (session-*.js, autosize-*.js) pile up
    # across builds and a stale one would ship unreferenced.
    rm -rf "$ROOT/dist"

    # `npm run build` runs the i18n extractor first, so TransStrings.php is
    # regenerated from the Vue sources as part of this.
    (cd "$ROOT" && npm run build) || die "npm run build failed."
else
    warn "Skipping the frontend build -- dist/ is whatever was left there last."
fi

# The public app, the admin app and the block are all required at runtime.
# Without this check a build with a half-finished frontend zips up quietly and
# only shows itself as a blank screen in production.
for required in \
    dist/js/app.js dist/css/app.css \
    dist/js/admin_app.js dist/css/admin_app.css \
    dist/js/native-comments.js \
    dist/block/editor.jsx.js dist/block/editor.jsx.asset.php
do
    [[ -e "$ROOT/$required" ]] || die "$required is missing. The frontend build did not complete."
done

# TransStrings.php is generated, gitignored by nobody, and easy to leave stale
# if someone ran --skip-build after editing a $t() string. Cheap to check.
if [[ ! -f "$ROOT/app/Services/TransStrings.php" ]]; then
    die "app/Services/TransStrings.php is missing. Run: npm run i18n"
fi

# ------------------------------------------------------------------- .pot ---

if "$withPot"; then
    step "Regenerating languages/$SLUG.pot"

    command -v wp >/dev/null || die "wp-cli not found."

    # resources/ is excluded because make-pot cannot read a .vue or .svelte
    # file; those strings arrive through TransStrings.php and Frontend.php
    # instead. dist/js and dist/css are excluded because the admin strings
    # would then be listed twice, the second time against a minified file.
    # dist/block is deliberately *not* excluded: the block editor's __() calls
    # only exist in the built bundle.
    wp i18n make-pot "$ROOT" "$ROOT/languages/$SLUG.pot" --slug="$SLUG" \
        --exclude=node_modules,tests,resources,dist/js,dist/css \
        || die "wp i18n make-pot failed."
fi

# --------------------------------------------------------------- assemble ---

step "Staging files"

# A whitelist fails closed: something new in the repo root is silently left out
# rather than wrongly included. That is the safer direction, but it is also
# quiet, and a missing directory only shows up as a broken feature in
# production. So say out loud what was skipped and was not expected to be.
UNKNOWN=()
for entry in "$ROOT"/* "$ROOT"/.[!.]*; do
    [[ -e "$entry" ]] || continue
    name="$(basename "$entry")"
    printf '%s\n' "${PAYLOAD[@]}" | grep -qxF "$name" && continue
    printf '%s\n' "${NOT_SHIPPED[@]}" | grep -qxF "$name" && continue
    UNKNOWN+=("$name")
done

if [[ ${#UNKNOWN[@]} -gt 0 ]]; then
    printf '\n\033[33m  Not shipped, and not on either list:\033[0m\n'
    printf '    %s\n' "${UNKNOWN[@]}"
    printf '  Add each to PAYLOAD to ship it, or to NOT_SHIPPED to confirm it should be left out.\n'
fi

rm -rf "$STAGE"
mkdir -p "$STAGE/$SLUG"

for item in "${PAYLOAD[@]}"; do
    [[ -e "$ROOT/$item" ]] || die "Expected to ship '$item' but it does not exist."
    cp -R "$ROOT/$item" "$STAGE/$SLUG/"
done

for file in "${PAYLOAD_FILES[@]}"; do
    [[ -f "$ROOT/$file" ]] || die "Expected to ship '$file' but it does not exist."
    mkdir -p "$STAGE/$SLUG/$(dirname "$file")"
    cp "$ROOT/$file" "$STAGE/$SLUG/$file"
done

# ------------------------------------------------------------------ clean ---

step "Removing build and editor artifacts"

for pattern in "${JUNK[@]}"; do
    find "$STAGE/$SLUG" -name "$pattern" -exec rm -rf {} + 2>/dev/null || true
done

# Extended attributes and resource forks travel with cp on macOS and become
# ._ files inside the archive on some unzip implementations.
if command -v xattr >/dev/null; then
    xattr -cr "$STAGE/$SLUG" 2>/dev/null || true
fi

# -------------------------------------------------------- silence is golden ---

step "Adding index.php to directories that have none"

# A server with directory indexes turned on will list the contents of any
# plugin folder to anyone who asks for it. The usual answer is an empty
# index.php in every directory, and here they were being added by hand -- which
# drifts: the tree has three of them across sixteen directories, so app/Hooks,
# app/Services, app/Views and the whole of dist/ go uncovered.
#
# Done on the staging copy, after the junk sweep so nothing removes them again.
# Existing files are never overwritten -- languages/index.php carries its own
# wording.
ADDED=0
while IFS= read -r -d '' dir; do
    if [[ ! -e "$dir/index.php" ]]; then
        printf '<?php\n// silence is golden\n' > "$dir/index.php"
        ADDED=$((ADDED + 1))
    fi
done < <(find "$STAGE/$SLUG" -type d -print0)

printf '  %s added, %s already present\n' \
    "$ADDED" "$(( $(find "$STAGE/$SLUG" -name index.php | wc -l) - ADDED ))"

# --------------------------------------------------------------------- zip ---

step "Creating archive"

command -v zip >/dev/null || die "zip not found."

rm -f "$ZIP"
# -X drops uids, gids and extended attributes, so the archive carries nothing
# about the machine that built it. (It is not bit-for-bit reproducible: zip
# stores mtimes, and the index.php files below are written fresh each run.)
# Built from the stage's parent so paths start with the plugin slug.
(cd "$STAGE" && zip -qrX "$ZIP" "$SLUG")

rm -rf "$STAGE"

[[ -f "$ZIP" ]] || die "The archive was not created."

# ------------------------------------------------------------------ report ---

FILE_COUNT="$(unzip -Z1 "$ZIP" | grep -vc '/$' || true)"
SHA="$(shasum -a 256 "$ZIP" | cut -d' ' -f1)"
SIZE="$(du -h "$ZIP" | cut -f1 | tr -d '[:space:]')"

printf '\n\033[32m==>\033[0m %s %s\n\n' "$SLUG" "$VERSION"
printf '  zip     %s\n' "$ZIP"
printf '  size    %s\n' "$SIZE"
printf '  files   %s\n' "$FILE_COUNT"
printf '  sha256  %s\n' "$SHA"

if unzip -Z1 "$ZIP" | grep -qE "/(node_modules|tests?)/|/resources/(js|admin|sass)/"; then
    printf '\n\033[31m  WARNING: the archive contains a directory that should not ship.\033[0m\n'
fi

# --------------------------------------------------------------------- s3 ---

if "$toS3"; then
    step "Uploading to S3"

    command -v aws >/dev/null || die "aws not found."

    # The S3 key stays unversioned (a stable "latest" download link); only the
    # local zip carries the version in its filename.
    aws s3 cp "$ZIP" "s3://wpcolorlab/$SLUG.zip" --acl public-read
fi

printf '\nNext: commit the zip contents to the WordPress.org SVN trunk and tag %s.\n\n' "$VERSION"
