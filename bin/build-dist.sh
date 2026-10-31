#!/usr/bin/env bash
set -euo pipefail
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
exec php "$PLUGIN_DIR/bin/build-dist.php" "$@"
