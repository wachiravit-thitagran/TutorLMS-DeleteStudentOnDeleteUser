#!/usr/bin/env bash
#
# Build an installable plugin zip.
#
# The archive contains exactly one top-level directory, named after the slug
# WordPress installs this plugin into: tutor-user-cleanup. The repository is
# called TutorLMS-DeleteStudentOnDeleteUser, which is *not* the slug, so the
# name comes from evidence inside the plugin instead:
#
#   * the main file is tutor-user-cleanup.php, and WordPress derives a plugin's
#     folder from the upload, keying it as <folder>/tutor-user-cleanup.php;
#   * "Text Domain: tutor-user-cleanup" in the header;
#   * TUTOR_USER_CLEANUP_BASENAME is plugin_basename( __FILE__ ), and the
#     textdomain is loaded from dirname( TUTOR_USER_CLEANUP_BASENAME ) .
#     '/languages' — so the folder has to be the one holding languages/;
#   * README.md documents "copy into wp-content/plugins/tutor-user-cleanup/"
#     and docs/REFINED-PLAN.md states 'Plugin slug: tutor-user-cleanup'.
#
# A single top-level directory with that name is what lets the "Replace current
# with uploaded" prompt overwrite the existing install instead of dropping a
# second copy beside it.
#
# There is no composer.json and no vendor/ in this plugin, so nothing has to be
# installed to build it. What the plugin actually loads at runtime is:
#
#   * includes/class-*.php — the spl_autoload_register() in the main file maps
#     every Tutor_User_Cleanup_* class to includes/class-<name>.php; all seven
#     classes it can resolve are required below;
#   * assets/admin.css and assets/admin.js — enqueued by Admin_UI against
#     TUTOR_USER_CLEANUP_URL;
#   * uninstall.php — run by WordPress on delete, so it must ship;
#   * readme.txt — WordPress reads it on the Plugins screen.
#
# Usage: bin/build.sh
# Output: dist/tutor-user-cleanup.zip
#
set -euo pipefail

PLUGIN_SLUG="tutor-user-cleanup"
MAIN_FILE="${PLUGIN_SLUG}.php"
DIST_DIR="dist"
BUILD_DIR="build/${PLUGIN_SLUG}"

# Runnable from anywhere.
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ ! -f "$MAIN_FILE" ]; then
	echo "Cannot find ${MAIN_FILE} — is this the plugin repository?" >&2
	exit 1
fi

# Read the header value whole. A loose "three numbers" grep would quietly turn
# 1.1.0-dev into 1.1.0 and claim the zip is a release build; here the string is
# validated as semver and then carried through unchanged, so an unstamped
# working copy is visible in the output instead of being disguised.
VERSION=$(grep -iE '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN_FILE" | head -1 | sed -E 's/.*[Vv]ersion:[[:space:]]*//' | tr -d '[:space:]')

if ! printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'; then
	echo "Could not read a semver Version header from ${MAIN_FILE} (got '${VERSION}')." >&2
	exit 1
fi

echo "Building ${PLUGIN_SLUG} ${VERSION}"

case "$VERSION" in
	*-*|*+*)
		echo "Note: this is a pre-release header. A release build is stamped first." >&2
		;;
esac

# Only remove what this script owns.
rm -rf "$BUILD_DIR" "$DIST_DIR"
mkdir -p "$BUILD_DIR" "$DIST_DIR"

echo "Staging files..."
# -m prunes empty directory chains from the transfer. A working copy carries
# whatever empty directories it happens to have and a fresh CI checkout does
# not, so pruning them keeps the archive identical either way — and a directory
# left behind holding nothing but excluded files never reaches the zip.
rsync -a -m \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.gitignore' \
	--exclude='.gitattributes' \
	--exclude='.editorconfig' \
	--exclude='/tests' \
	--exclude='/bin' \
	--exclude='/docs' \
	--exclude='/build' \
	--exclude='/dist' \
	--exclude='/vendor' \
	--exclude='/node_modules' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='phpunit*.xml.dist' \
	--exclude='phpunit*.xml' \
	--exclude='phpcs.xml.dist' \
	--exclude='phpcs.xml' \
	--exclude='phpcs-report.xml' \
	--exclude='.phpunit*.cache' \
	--exclude='.phpunit.result.cache' \
	--exclude='*.md' \
	--exclude='*.zip' \
	--exclude='*.log' \
	--exclude='.DS_Store' \
	--exclude='Thumbs.db' \
	./ "$BUILD_DIR/"

# languages/ is not in git (no .pot is committed yet) but the header advertises
# "Domain Path: /languages" and the main file loads translations from
# dirname( TUTOR_USER_CLEANUP_BASENAME ) . '/languages', so the directory ships
# regardless — ready for the .mo files a site operator or translator drops in.
mkdir -p "$BUILD_DIR/languages"

echo "Creating the archive..."
(
	cd build
	zip -rq "../${DIST_DIR}/${PLUGIN_SLUG}.zip" "$PLUGIN_SLUG" -x '*.DS_Store'
)

ZIP="${DIST_DIR}/${PLUGIN_SLUG}.zip"

echo "Verifying the archive..."

# Listed once: piping into `grep -q` would SIGPIPE unzip and trip pipefail.
entries=$(unzip -Z1 "$ZIP")

# Exactly one top-level directory, and it must be the install slug.
top_level=$(awk -F/ 'NF > 0 { print $1 }' <<<"$entries" | sort -u)
if [ "$top_level" != "$PLUGIN_SLUG" ]; then
	echo "Archive must have exactly one top-level directory named ${PLUGIN_SLUG}, found:" >&2
	printf '%s\n' "$top_level" >&2
	exit 1
fi

# Nothing that belongs to development may leak onto a production site.
# readme.txt is deliberately not caught here: WordPress reads it.
if grep -E "^${PLUGIN_SLUG}/(tests|bin|docs|vendor|build|dist|node_modules|\.git|\.github)/|^${PLUGIN_SLUG}/(composer\.(json|lock)|phpunit.*\.xml.*|phpcs.*\.(xml|dist).*|\.git.*|.*\.md)$" <<<"$entries"; then
	echo "The lines above should not be in a production build." >&2
	exit 1
fi

# The things a site does need: the bootstrap, the uninstaller, the readme
# WordPress parses, every class the autoloader can resolve, both enqueued
# assets, and the translations directory the header points at.
for required in \
	"${PLUGIN_SLUG}/${MAIN_FILE}" \
	"${PLUGIN_SLUG}/uninstall.php" \
	"${PLUGIN_SLUG}/readme.txt" \
	"${PLUGIN_SLUG}/includes/class-plugin.php" \
	"${PLUGIN_SLUG}/includes/class-admin-ui.php" \
	"${PLUGIN_SLUG}/includes/class-cleanup-service.php" \
	"${PLUGIN_SLUG}/includes/class-logger.php" \
	"${PLUGIN_SLUG}/includes/class-reset-learner.php" \
	"${PLUGIN_SLUG}/includes/class-settings.php" \
	"${PLUGIN_SLUG}/includes/class-tutor-data-repository.php" \
	"${PLUGIN_SLUG}/assets/admin.css" \
	"${PLUGIN_SLUG}/assets/admin.js" \
	"${PLUGIN_SLUG}/languages/"; do
	if ! grep -qxF "$required" <<<"$entries"; then
		echo "Missing from the archive: ${required}" >&2
		exit 1
	fi
done

# The zip must carry the same version the header claims.
zipped_header=$(unzip -p "$ZIP" "${PLUGIN_SLUG}/${MAIN_FILE}")
zipped_version=$(grep -iE '^[[:space:]]*\*[[:space:]]*Version:' <<<"$zipped_header" | head -1 | sed -E 's/.*[Vv]ersion:[[:space:]]*//' | tr -d '[:space:]')
if [ "$zipped_version" != "$VERSION" ]; then
	echo "Archive reports version '${zipped_version}', expected '${VERSION}'." >&2
	exit 1
fi

# The runtime constant has to agree with the header, or a site will cache-bust
# its admin CSS/JS against a version it is not running.
zipped_constant=$(grep -E "'TUTOR_USER_CLEANUP_VERSION'" <<<"$zipped_header" | head -1 | sed -E "s/.*'TUTOR_USER_CLEANUP_VERSION',[[:space:]]*'([^']*)'.*/\1/")
if [ "$zipped_constant" != "$VERSION" ]; then
	echo "Archive TUTOR_USER_CLEANUP_VERSION is '${zipped_constant}', expected '${VERSION}'." >&2
	exit 1
fi

# TUTOR_USER_CLEANUP_MIN_TUTOR_VERSION and ..._TESTED_TUTOR_VERSION sit right
# next to the plugin version and describe the *Tutor LMS* range this plugin was
# validated against — they have nothing to do with the release number and a
# careless version stamp must never touch them. The cleanup service refuses live
# deletion when the installed Tutor version falls outside that range, so a
# clobbered value would either block every site or silently permit deletions
# against an unvalidated schema. Assert both survived as plain semver.
for const in TUTOR_USER_CLEANUP_MIN_TUTOR_VERSION TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION; do
	value=$(grep -E "'${const}'" <<<"$zipped_header" | head -1 | sed -E "s/.*'${const}',[[:space:]]*'([^']*)'.*/\1/")
	if ! printf '%s' "$value" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
		echo "Archive ${const} is '${value}', which is not a Tutor LMS version; a version stamp has damaged it." >&2
		exit 1
	fi
	echo "  ${const}: ${value} (Tutor LMS range, not the release version)"
done

# The stamp targets the plugin version alone. If it ever matched one of the
# Tutor range constants as well they would read as the release number, which is
# exactly the failure this guards.
if [ "$(grep -cE "'(TUTOR_USER_CLEANUP_MIN_TUTOR_VERSION|TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION)',[[:space:]]*'${VERSION}'" <<<"$zipped_header" || true)" != "0" ]; then
	echo "A Tutor LMS range constant was overwritten with the release version ${VERSION}." >&2
	exit 1
fi

echo
echo "Build complete: ${ZIP} ($(du -h "$ZIP" | cut -f1), $(wc -l <<<"$entries" | tr -d ' ') entries, version ${VERSION})"
