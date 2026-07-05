#!/bin/sh
set -eu

: "${POSTGRES_DB:?POSTGRES_DB must be set}"
: "${POSTGRES_USER:?POSTGRES_USER must be set}"
: "${APP_DB_USER:?APP_DB_USER must be set}"
: "${APP_DB_PASS:?APP_DB_PASS must be set}"

sql_escape() {
  printf '%s' "$1" | sed "s/'/''/g"
}

POSTGRES_DB_ESC="$(sql_escape "${POSTGRES_DB}")"
APP_DB_USER_ESC="$(sql_escape "${APP_DB_USER}")"
APP_DB_PASS_ESC="$(sql_escape "${APP_DB_PASS}")"

psql \
  -v ON_ERROR_STOP=1 \
  --username "${POSTGRES_USER}" \
  --dbname postgres <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${APP_DB_USER_ESC}') THEN
    EXECUTE format(
      'CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE INHERIT NOBYPASSRLS',
      '${APP_DB_USER_ESC}',
      '${APP_DB_PASS_ESC}'
    );
  ELSE
    EXECUTE format(
      'ALTER ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE INHERIT NOBYPASSRLS',
      '${APP_DB_USER_ESC}',
      '${APP_DB_PASS_ESC}'
    );
  END IF;

  EXECUTE format('ALTER DATABASE %I OWNER TO %I', '${POSTGRES_DB_ESC}', '${APP_DB_USER_ESC}');
  EXECUTE format('REVOKE ALL ON DATABASE %I FROM PUBLIC', '${POSTGRES_DB_ESC}');
  EXECUTE format('GRANT CONNECT, TEMPORARY ON DATABASE %I TO %I', '${POSTGRES_DB_ESC}', '${APP_DB_USER_ESC}');
END
\$\$;
SQL

psql \
  -v ON_ERROR_STOP=1 \
  --username "${POSTGRES_USER}" \
  --dbname "${POSTGRES_DB}" <<SQL
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

SELECT format('GRANT USAGE, CREATE ON SCHEMA public TO %I', '${APP_DB_USER_ESC}') \gexec
SQL
