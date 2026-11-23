#!/bin/sh
# Builds the archive of a release of iRest for Dolibarr, installable from Home > Setup > Modules > Deploy an external
# module: module_irest-X.Y.Z.zip, with the folder irest/ at its root, and its SHA-256.
#
# Usage: build-release.sh vX.Y.Z [tree]
# tree: what to archive (HEAD by default). DIST_DIR: where to write (dist by default).
set -eu

tag="${1:?Give the tag, e.g. v1.1.0}"
tree="${2:-HEAD}"
dist="${DIST_DIR:-dist}"
version="${tag#v}"

echo "$tag" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$' || { echo "Not a release tag: $tag" >&2; exit 1; }

# The tag must be the version declared everywhere in the code
declared=$(sed -n "s/.*\$this->version = '\([^']*\)'.*/\1/p" core/modules/modIrest.class.php)
client=$(sed -n "s/.*const MODULE_VERSION = '\([^']*\)'.*/\1/p" class/imrstclient.class.php)
[ "$declared" = "$version" ] || { echo "modIrest declares $declared, the tag is $tag" >&2; exit 1; }
[ "$client" = "$version" ] || { echo "ImRstClient declares $client, the tag is $tag" >&2; exit 1; }
grep -q "^## \[$version\]" ChangeLog.md || { echo "No entry for $version in ChangeLog.md" >&2; exit 1; }

mkdir -p "$dist"
archive="module_irest-$version.zip"
git archive --format=zip --prefix=irest/ -o "$dist/$archive" "$tree"
(cd "$dist" && sha256sum "$archive" > "$archive.sha256")
echo "$dist/$archive"
