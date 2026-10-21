#!/usr/bin/env bash
# Turns a plugin folder (as shipped: git archive) into the wordpress.org edition:
# no GitHub updater, no Update URI, no manual translation loading, no pre-release migrations.
# Usage: bin/build-wporg.sh <plugin-folder>
set -euo pipefail
DIR="${1:?plugin folder}"
MAIN="$DIR/translation-watchdog-for-translatepress.php"

rm -f "$DIR/includes/updater.php"
sed -i '/^ \* Update URI:/d' "$MAIN"
for f in "$MAIN" "$DIR/includes/admin.php"; do
    sed -i '/@wporg-remove-start/,/@wporg-remove-end/d' "$f"
done

# nothing GitHub-specific may survive
if grep -rnE "updater\.php|Update URI|load_plugin_textdomain|@wporg-remove|cstw_" "$DIR" --include=*.php; then
    echo "wporg build: GitHub-only code left (see above)" >&2
    exit 1
fi
echo "wporg build ok: $DIR"
