#!/usr/bin/env bash
#
# Start the local environment.
#
#   ./dev.sh              start the server (setting everything up the first time)
#   ./dev.sh --fresh      throw the local database away and rebuild it
#   ./dev.sh --port 8080  on a different port
#   ./dev.sh --csv FILE   import this CSV when building the database
#
# Nothing a server would have is touched: the local database is an SQLite file
# under storage/, and its configuration is config.dev.php. Both are excluded
# from Git.

set -euo pipefail
cd "$(dirname "$0")"

PORT=8931
FRESH=0
CSV="Bücher.csv"

while [ $# -gt 0 ]; do
  case "$1" in
    --fresh|--neu) FRESH=1; shift ;;
    --port) PORT="$2"; shift 2 ;;
    --csv) CSV="$2"; shift 2 ;;
    -h|--help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

DB="storage/dev.sqlite"
CONFIG="config.dev.php"

command -v php >/dev/null || { echo "PHP is missing." >&2; exit 1; }

mkdir -p storage

if [ "$FRESH" = "1" ]; then
  echo "Rebuilding the local database."
  rm -f "$DB"
fi

# --- Configuration ---------------------------------------------------------
if [ ! -f "$CONFIG" ]; then
  cat > "$CONFIG" <<PHPEOF
<?php
/**
 * Configuration for the local development environment.
 *
 * Written by dev.sh and excluded from Git. On a server there is config.php
 * with real credentials instead.
 */
declare(strict_types=1);

return [
    'db_dsn'     => 'sqlite:' . __DIR__ . '/storage/dev.sqlite',
    'db_host'    => '', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'db_charset' => 'utf8mb4',

    'site_name' => 'Mein Regal',
    'site_url'  => 'http://localhost:$PORT',
    'blog_url'  => '',
    'blog_name' => '',
    'locale'    => 'de',

    'public_stats' => true,

    'legal' => [
        'operator' => 'Local only',
        'street'   => 'Teststrasse 1',
        'city'     => '00000 Testtown',
        'email'    => 'local@example.org',
        'host'     => 'Local machine, no hosting company',
        'mstv_responsible' => '',
    ],

    'google_books_key' => '',
    'cron_secret'      => 'local-only-but-still-long-enough',
    'api_contact'      => '',
];
PHPEOF
  echo "  $CONFIG written."
fi

# --- Database --------------------------------------------------------------
if [ ! -f "$DB" ]; then
  echo "Creating the database."
  REGAL_CONFIG="$PWD/$CONFIG" php -r '
    require "app/bootstrap.php";
    require "tests/support/SqliteSchema.php";
    $pdo = new PDO("sqlite:" . PROJECT_ROOT . "/storage/dev.sqlite");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    Tests\Support\SqliteSchema::apply($pdo, PROJECT_ROOT . "/schema.sql");
  '

  REGAL_CONFIG="$PWD/$CONFIG" php bin/setup.php \
    --sqlite="$DB" --email=local@example.org --name="Local" --password=local-test-password \
    | sed 's/^/  /'
  echo "  Sign in: local@example.org / local-test-password"

  if [ -f "$CSV" ]; then
    echo "Importing from $CSV."
    REGAL_CONFIG="$PWD/$CONFIG" php bin/import.php --file="$CSV" --sqlite="$DB" --commit \
      | grep -E "imported|Authors|Tags" | sed 's/^/  /'
  else
    echo "  ($CSV not found - the shelf stays empty. Use --csv PATH to import one.)"
  fi
fi

BOOKS=$(php -r "\$p = new PDO('sqlite:$DB'); echo \$p->query('SELECT COUNT(*) FROM books')->fetchColumn();")

echo
echo "  http://localhost:$PORT"
echo "  $BOOKS books | sign in: local@example.org / local-test-password"
echo "  Stop with Ctrl-C"
echo

exec env REGAL_CONFIG="$PWD/$CONFIG" php -S "localhost:$PORT" -t public router.dev.php
