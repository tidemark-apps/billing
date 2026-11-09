#!/usr/bin/env bash
# Builds a zip you can upload to any PHP 8.3+ shared host: dependencies are bundled, so no SSH/Composer needed there.
# Usage: scripts/build-release.sh [version]
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION="${1:-$(date +%Y.%m.%d)}"
NAME="tidemark-billing-${VERSION}"
OUT="dist"
STAGE="$(mktemp -d)/${NAME}"

echo "→ Staging ${NAME}"
mkdir -p "$STAGE" "$OUT"
rsync -a --exclude='.git' --exclude='.env' --exclude='.env.backup' --exclude='/vendor' --exclude='/node_modules' \
  --exclude='/dist' --exclude='/tests' --exclude='/storage/logs/*' --exclude='/storage/framework/cache/data/*' \
  --exclude='/storage/framework/sessions/*' --exclude='/storage/framework/views/*' --exclude='/storage/app/installed' --exclude='/storage/app/dompdf' --exclude='/storage/app/plugin-*' \
  --exclude='/database/*.sqlite' --exclude='/bootstrap/cache/*.php' --exclude='.phpunit.*' --exclude='phpunit.xml' \
  --exclude='/plugins/*/vendor' --exclude='/.github' --exclude='/.editorconfig' --exclude='/Dockerfile' --exclude='/docker' \
  --exclude='/docker-compose*.yml' --exclude='/.dockerignore' --exclude='.DS_Store' \
  ./ "$STAGE/"

echo "$VERSION" > "$STAGE/VERSION"

echo "→ Installing production dependencies"
( cd "$STAGE" && composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-scripts --quiet )

# Ensure writable runtime folders exist
mkdir -p "$STAGE"/storage/{app/public,app/private,framework/{cache/data,sessions,views},logs} "$STAGE"/bootstrap/cache
touch "$STAGE"/storage/logs/.gitkeep

echo "→ Zipping"
( cd "$(dirname "$STAGE")" && zip -qr "$OLDPWD/${OUT}/${NAME}.zip" "${NAME}" )
echo "✓ ${OUT}/${NAME}.zip ($(du -h "${OUT}/${NAME}.zip" | cut -f1))"
