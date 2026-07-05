#!/bin/sh
set -eu

cd /var/www/html

if [ ! -f package.json ]; then
  echo "package.json not found, skipping npm dependency bootstrap."
  exit 0
fi

if [ -f package-lock.json ]; then
  LOCK_HASH="$(sha256sum package-lock.json | awk '{print $1}')"
  CURRENT_HASH=""
  if [ -f node_modules/.package-lock-hash ]; then
    CURRENT_HASH="$(cat node_modules/.package-lock-hash)"
  fi

  if [ ! -d node_modules ] || [ -z "$(ls -A node_modules 2>/dev/null)" ] || [ "${LOCK_HASH}" != "${CURRENT_HASH}" ] || [ ! -x node_modules/.bin/vite ]; then
    echo "Installing npm dependencies via npm ci..."
    npm ci --no-audit --no-fund
    printf '%s' "${LOCK_HASH}" > node_modules/.package-lock-hash
  fi
else
  if [ ! -d node_modules ] || [ -z "$(ls -A node_modules 2>/dev/null)" ]; then
    echo "package-lock.json missing, installing npm dependencies via npm install..."
    npm install --no-audit --no-fund
  fi
fi
