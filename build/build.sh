#!/usr/bin/env bash
# Build the distributable OCMOD archives:
#   dist/ai_admin_oc3.ocmod.zip  - OpenCart 3.0.x  (Extensions > Installer, then Extensions > Modules > MCP Server > Install)
#   dist/ai_admin_oc4.ocmod.zip          - OpenCart 4.0.2+/4.1.x (the file name defines the extension code "ai_admin_oc4": do not rename)
#
# Release builds encode the core (system/library/mcp, including the license check) with the ionCube Encoder:
#   IONCUBE_ENCODER=/path/to/ioncube_encoder81_64 build/build.sh        (extra options: IONCUBE_OPTIONS="--optimize max")
# autoload.php stays plain PHP: it tells the shop owner when the hosting has no ionCube Loader.
# Without IONCUBE_ENCODER the archives are NOT protected (the license check can be edited out) - for testing only.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="${ROOT:?}/dist"
TMP="$(mktemp -d)"
trap 'rm -rf "${TMP:?}"' EXIT
python3 "$ROOT/build/gen_admin.py" --check
mkdir -p "$DIST"
find "${DIST:?}" -maxdepth 1 -name '*.ocmod.zip' -delete

encode_core() { # $1 = directory with the plain core; replaced in place by the encoded core
  [ -n "${IONCUBE_ENCODER:-}" ] || return 0
  mv "$1" "$1.plain"
  # shellcheck disable=SC2086
  "$IONCUBE_ENCODER" ${IONCUBE_OPTIONS:-} --copy "autoload.php" "$1.plain" -o "$1"
  touch "$1/.encoded"
  rm -rf "${1:?}.plain"
}

# the build number goes into the archive copy of the core and into install.json
BUILD="$(date +%Y-%m-%d)-$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo local)$(git -C "$ROOT" diff --quiet 2>/dev/null || echo '+')"
NUMBER="$(sed -n "s/.*const NUMBER = '\([^']*\)'.*/\1/p" "$ROOT/src/mcp/Version.php")"
stamp() { sed -i.bak "s/const BUILD = 'dev';/const BUILD = '$BUILD';/" "$1/Version.php" && rm -f "$1/Version.php.bak"; }

# OC3: upload/ mirrors the store root; the shared core goes to upload/system/library/mcp
mkdir -p "$TMP/oc3/upload/system/library/mcp"
cp -R "$ROOT/packages/oc3/upload/." "$TMP/oc3/upload/"
cp -R "$ROOT/src/mcp/." "$TMP/oc3/upload/system/library/mcp/"
rm -f "$TMP/oc3/upload/system/library/mcp/assets/admin.oc4.js" # each archive carries only its own version's admin script
stamp "$TMP/oc3/upload/system/library/mcp"
encode_core "$TMP/oc3/upload/system/library/mcp"
cp "$ROOT/packages/oc3/install.xml" "$TMP/oc3/install.xml" # OCMOD: the MCP route stays open in maintenance mode
( cd "$TMP/oc3" && zip -qr -X "$DIST/ai_admin_oc3.ocmod.zip" upload install.xml -x '*.DS_Store' -x '*/.mcp-dev' )

# OC4: admin/ catalog/ system/ + install.json at the archive root -> extension/ai_admin_oc4/
mkdir -p "$TMP/oc4/system/library/mcp"
cp -R "$ROOT/packages/oc4/." "$TMP/oc4/"
cp -R "$ROOT/src/mcp/." "$TMP/oc4/system/library/mcp/"
rm -f "$TMP/oc4/system/library/mcp/assets/admin.oc3.js"
stamp "$TMP/oc4/system/library/mcp"
sed -i.bak "s/\"version\": \"[^\"]*\"/\"version\": \"$NUMBER\"/" "$TMP/oc4/install.json" && rm -f "$TMP/oc4/install.json.bak"
encode_core "$TMP/oc4/system/library/mcp"
( cd "$TMP/oc4" && zip -qr -X "$DIST/ai_admin_oc4.ocmod.zip" . -x '*.DS_Store' -x '*/.mcp-dev' )

ls -la "$DIST"
echo "version $NUMBER, build $BUILD"
if [ -z "${IONCUBE_ENCODER:-}" ]; then
  echo
  echo "WARNING: built WITHOUT ionCube: the core is plain PHP and the license check can be removed."
  echo "         Release builds: IONCUBE_ENCODER=/path/to/ioncube_encoder build/build.sh"
fi
