#!/usr/bin/env bash
# Build the WordPress.org distribution zip.
set -euo pipefail

SLUG="otp-verification-sms-for-gravity-forms"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$DIST" "$STAGE/$SLUG"
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGE/$SLUG/"

rm -f "$DIST/$SLUG.zip"
(cd "$STAGE" && zip -rq "$DIST/$SLUG.zip" "$SLUG")

echo "Built $DIST/$SLUG.zip"
