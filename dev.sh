#!/usr/bin/env bash
#
# Die lokale Umgebung starten.
#
#   ./dev.sh              startet den Server (und richtet beim ersten Mal alles ein)
#   ./dev.sh --neu        wirft die lokale Datenbank weg und baut sie neu auf
#   ./dev.sh --port 8080  auf einem anderen Port
#
# Es wird nichts angefasst, was auf einem Server stünde: die lokale Datenbank
# ist eine SQLite-Datei unter storage/, und die Konfiguration dazu liegt in
# config.dev.php. Beides ist von Git ausgenommen.

set -euo pipefail
cd "$(dirname "$0")"

PORT=8931
FRESH=0
CSV="Bücher.csv"

while [ $# -gt 0 ]; do
  case "$1" in
    --neu|--fresh) FRESH=1; shift ;;
    --port) PORT="$2"; shift 2 ;;
    --csv) CSV="$2"; shift 2 ;;
    -h|--help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unbekannte Option: $1" >&2; exit 1 ;;
  esac
done

DB="storage/dev.sqlite"
CONFIG="config.dev.php"

command -v php >/dev/null || { echo "PHP fehlt." >&2; exit 1; }

mkdir -p storage

if [ "$FRESH" = "1" ]; then
  echo "Lokale Datenbank wird neu aufgebaut."
  rm -f "$DB"
fi

# --- Konfiguration ---------------------------------------------------------
if [ ! -f "$CONFIG" ]; then
  cat > "$CONFIG" <<PHPEOF
<?php
/**
 * Konfiguration der lokalen Entwicklungsumgebung.
 *
 * Von dev.sh angelegt und von Git ausgenommen. Auf einem Server steht
 * stattdessen config.php mit echten Zugangsdaten.
 */
declare(strict_types=1);

return [
    'db_dsn'     => 'sqlite:' . __DIR__ . '/storage/dev.sqlite',
    'db_host'    => '', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'db_charset' => 'utf8mb4',

    'site_name' => 'Mein Regal',
    'site_url'  => 'http://localhost:$PORT',
    'blog_url'  => 'https://www.buecherhausen.de/',
    'blog_name' => 'Bücherhausen',
    'locale'    => 'de',

    'legal' => [
        'operator' => 'Nur lokal',
        'street'   => 'Teststraße 1',
        'city'     => '00000 Testort',
        'email'    => 'lokal@example.org',
        'mstv_responsible' => '',
    ],

    'google_books_key' => '',
    'cron_secret'      => 'nur-lokal-und-trotzdem-lang-genug',
    'api_contact'      => '',
];
PHPEOF
  echo "  $CONFIG angelegt."
fi

# --- Datenbank -------------------------------------------------------------
if [ ! -f "$DB" ]; then
  echo "Datenbank wird angelegt."
  REGAL_CONFIG="$PWD/$CONFIG" php -r '
    require "app/bootstrap.php";
    require "tests/support/SqliteSchema.php";
    $pdo = new PDO("sqlite:" . PROJECT_ROOT . "/storage/dev.sqlite");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    Tests\Support\SqliteSchema::apply($pdo, PROJECT_ROOT . "/schema.sql");
  '

  REGAL_CONFIG="$PWD/$CONFIG" php bin/setup.php \
    --sqlite="$DB" --email=lokal@example.org --name="Lokal" --password=lokales-testpasswort \
    | sed 's/^/  /'
  echo "  Anmeldung: lokal@example.org / lokales-testpasswort"

  if [ -f "$CSV" ]; then
    echo "Bestand wird eingelesen aus $CSV."
    REGAL_CONFIG="$PWD/$CONFIG" php bin/import.php --file="$CSV" --sqlite="$DB" --commit \
      | grep -E "importiert|Autor|Tags" | sed 's/^/  /'
  else
    echo "  ($CSV nicht gefunden - das Regal bleibt leer. Mit --csv PFAD nachholen.)"
  fi
fi

BOOKS=$(php -r "\$p = new PDO('sqlite:$DB'); echo \$p->query('SELECT COUNT(*) FROM books')->fetchColumn();")

echo
echo "  http://localhost:$PORT"
echo "  $BOOKS Bücher | Anmeldung: lokal@example.org / lokales-testpasswort"
echo "  Beenden mit Strg-C"
echo

exec env REGAL_CONFIG="$PWD/$CONFIG" php -S "localhost:$PORT" -t public router.dev.php
