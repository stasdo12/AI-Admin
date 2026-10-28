#!/usr/bin/env bash
# Lint every PHP file and run the protocol tests in Docker (no local PHP needed).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "== admin views generated from packages/shared, language keys complete"
python3 "$ROOT/build/gen_admin.py" --check
for v in 7.1 7.4 8.3; do
  echo "== php $v: lint"
  docker run --rm -v "$ROOT":/app -w /app "php:$v-cli" sh -c 'find src packages tests -name "*.php" -print0 | xargs -0 -n1 php -l 2>&1 | grep -v "^No syntax errors" || true'
  echo "== php $v: tests (free build)"
  docker run --rm -v "$ROOT":/app -w /app "php:$v-cli" php tests/run_free.php
done
