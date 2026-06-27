#!/usr/bin/env bash
#
# Build a clean, distributable plugin zip.
#
# Uses `git archive`, which honors the `export-ignore` attributes in
# .gitattributes — so development files (docs/, build-plan.md, .editorconfig,
# phpcs.xml.dist, etc.) are excluded automatically. The resulting zip is what
# you run Plugin Check against and what gets committed to the WordPress.org SVN.
#
# Usage: bin/build-zip.sh [output-dir]
set -euo pipefail

SLUG="ndv-reviews"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-${ROOT}/dist}"
VERSION="$(grep -E '^\s*\*\s*Version:' "${ROOT}/${SLUG}.php" | head -1 | sed -E 's/.*Version:\s*//' | tr -d '[:space:]')"

mkdir -p "${OUT_DIR}"
ZIP="${OUT_DIR}/${SLUG}-${VERSION}.zip"

echo "Building ${ZIP} from git HEAD (export-ignore honored)…"
cd "${ROOT}"
git archive --format=zip --prefix="${SLUG}/" -o "${ZIP}" HEAD

echo "Done: ${ZIP}"
echo "Contents:"
unzip -l "${ZIP}" | sed -n '1,40p'
