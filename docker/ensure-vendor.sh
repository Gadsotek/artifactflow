#!/bin/sh
set -eu

cd /var/www/html

if command -v git >/dev/null 2>&1; then
  git config --global --add safe.directory /var/www/html >/dev/null 2>&1 || true
fi

if [ ! -f composer.json ]; then
  echo "composer.json not found, skipping dependency bootstrap."
  exit 0
fi

mkdir -p vendor

if [ -d vendor ] && [ ! -w vendor ]; then
  echo "vendor directory is not writable by container user ($(id -u):$(id -g))." >&2
  ls -ld vendor >&2 || true
  exit 1
fi

app_environment="${APP_ENV:-local}"
requires_dev_dependencies=1

if [ "${app_environment}" = "production" ]; then
  requires_dev_dependencies=0
fi

needs_install=0

composer_lock_hash=""
composer_lock_hash_file="vendor/.composer-lock-hash"

if [ -f composer.lock ]; then
  composer_lock_hash="$(sha256sum composer.lock | awk '{ print $1 }')"
fi

vendor_is_ready() {
  if [ ! -f vendor/autoload.php ]; then
    return 1
  fi

  if [ "${requires_dev_dependencies}" = "1" ] && [ ! -x vendor/bin/pest ]; then
    return 1
  fi

  if [ -n "${composer_lock_hash}" ]; then
    installed_lock_hash=""
    if [ -f "${composer_lock_hash_file}" ]; then
      installed_lock_hash="$(cat "${composer_lock_hash_file}")"
    fi

    [ "${installed_lock_hash}" = "${composer_lock_hash}" ] || return 1
  fi

  return 0
}

if ! vendor_is_ready; then
  needs_install=1
fi

lock_dir="vendor/.composer-install.lock"
owns_lock=0

release_lock() {
  if [ "${owns_lock}" = "1" ]; then
    rmdir "${lock_dir}" >/dev/null 2>&1 || true
  fi
}

if [ "${needs_install}" = "1" ]; then
  while ! mkdir "${lock_dir}" >/dev/null 2>&1; do
    if vendor_is_ready; then
      needs_install=0
      break
    fi

    echo "Another container is installing Composer dependencies, waiting..."
    sleep 2
  done

  if [ "${needs_install}" = "1" ]; then
    owns_lock=1
    trap release_lock EXIT INT TERM

    if ! vendor_is_ready; then
      if [ -f composer.lock ]; then
        echo "Installing Composer dependencies from composer.lock..."
        COMPOSER_MEMORY_LIMIT=-1 composer install --prefer-dist --no-interaction --no-progress
      else
        echo "composer.lock is missing; resolving dependencies and writing an initial lock file..."
        COMPOSER_MEMORY_LIMIT=-1 composer install --prefer-dist --no-interaction --no-progress
      fi

      if [ -f composer.lock ]; then
        composer_lock_hash="$(sha256sum composer.lock | awk '{ print $1 }')"
        printf '%s' "${composer_lock_hash}" > "${composer_lock_hash_file}"
      fi
    fi
  fi
fi

if [ ! -f vendor/autoload.php ]; then
  echo "vendor/autoload.php is missing after composer install." >&2
  exit 1
fi

if [ "${requires_dev_dependencies}" = "1" ] && [ ! -x vendor/bin/pest ]; then
  echo "vendor/bin/pest is missing after composer install." >&2
  exit 1
fi

if ! vendor_is_ready; then
  echo "Composer dependencies do not match composer.lock after installation." >&2
  exit 1
fi
