# Mein Regal

Ein Bücherregal zum Selbsthosten: Barcode scannen, Buchdaten holen, katalogisieren.
Läuft auf gewöhnlichem PHP-Webspace ohne Shell-Zugang.

Entstanden, weil [bookstats.de](https://bookstats.de) den Betrieb eingestellt hat und
eine über Jahre gewachsene Sammlung von 3.042 Büchern sonst nur noch als CSV-Datei
existiert hätte.

## Was es kann

- **Barcode scannen** direkt im Browser, am Handy wie am Rechner. Nutzt die
  eingebaute Erkennung, wo es sie gibt, und lädt sonst eine mitgelieferte
  Bibliothek nach.
- **Buchdaten automatisch holen** von der Deutschen Nationalbibliothek, Google Books
  und Open Library — in der Reihenfolge, die zur ISBN passt.
- **Cover** aus den freien Quellen oder selbst fotografiert.
- **Regal** mit Suche, Filtern und Sortierung; öffentlich sichtbar, Verwaltung hinter
  dem Login.
- **Statistik** öffentlich, **Dashboard** mit Datenqualität und Aufgabenliste privat.
- **Export** in drei Formaten und **Sicherung** von Datenbank, Katalog und Covern.
- Oberfläche auf Deutsch und Englisch.

## Voraussetzungen

PHP 8.1 oder neuer mit `pdo_mysql`, `gd`, `mbstring`, `curl` und `simplexml`.
MySQL oder MariaDB. HTTPS ist Pflicht — ohne verschlüsselte Verbindung gibt kein
Browser die Kamera frei.

Getestet auf all-inkl Privat+ (PHP 8.3, kein SSH). `intl` wird genutzt, wenn
vorhanden, ist aber nicht erforderlich.

## Einrichten

### 1. Dateien hochladen

Der Anwendungscode gehört **über** den Dokumentenstamm, nicht hinein:

```
regal/
  app/  bin/  migrations/  schema.sql
  config.php          <- Zugangsdaten, nur auf dem Server
  storage/            <- Logs und Sicherungen
  public/             <- hierauf zeigt der Dokumentenstamm
```

Im all-inkl-KAS unter *Domain → Subdomain* den Dokumentenstamm auf `regal/public/`
setzen. **Zeigt er auf `regal/` statt auf `regal/public/`, ist der komplette
Quellcode über HTTP abrufbar.** Das ist der eine Handgriff, der stimmen muss.

### 2. Datenbank anlegen

`schema.sql` einmalig über phpMyAdmin einspielen. Spätere Änderungen liegen als
datierte Dateien in `migrations/` und werden genauso eingespielt.

### 3. Konfigurieren

`config.sample.php` nach `config.php` kopieren und ausfüllen: Zugangsdaten,
Seitenname, Adressen für Impressum und Datenschutz, `cron_secret`. Die Datei
gehört neben `app/`, nicht in `public/`, und niemals ins Repository.

### 4. Konto anlegen

```bash
php bin/setup.php --email=du@example.org --name="Dein Name"
```

Ohne `--password` wird eines erzeugt und einmalig ausgegeben. Danach ist es nur
noch als Hash gespeichert.

### 5. Bestand importieren

```bash
php bin/import.php --file=Buecher.csv            # Trockenlauf, schreibt nichts
php bin/import.php --file=Buecher.csv --commit
```

Immer erst ohne `--commit` laufen lassen und den Bericht lesen: er nennt Dubletten,
fehlende ISBNs und Autorenfelder, die nicht eindeutig zu lesen waren.

### 6. Nächtlichen Cronjob einrichten

all-inkl ruft für Cronjobs eine Adresse auf, keine Datei. Im KAS unter *Tools →
Cronjobs* eintragen:

```
https://regal.example.org/cron?key=DEIN_CRON_SECRET
```

Der Auftrag sucht fehlende Cover und Angaben nach — gedrosselt, damit die
Tagesquoten der Datenquellen halten — und räumt abgelaufene Anmeldetoken weg.

## Werkzeuge

```bash
php bin/export.php --format=bookstats     # Originalformat, liest sich zurück
php bin/export.php --format=full          # alle Spalten, UTF-8
php bin/export.php --format=json          # alles, inklusive Beteiligter und Tags
php bin/backup.php --keep=30              # Datenbank, Katalog und Cover
php bin/enrich.php --limit=100            # Cover und Angaben nachtragen
php tests/run.php                         # Tests
```

Alle Skripte nehmen `--sqlite=/pfad/zur/datei` und laufen dann ohne `config.php`
gegen eine Wegwerf-Datenbank — so entsteht auch die Entwicklungsumgebung.

## Entwickeln

```bash
php bin/setup.php --sqlite=/tmp/regal.sqlite --email=du@example.org --name="Du"
php bin/import.php --file=Buecher.csv --sqlite=/tmp/regal.sqlite --commit
php -S localhost:8931 -t public router.dev.php
```

`config.php` mit `'db_dsn' => 'sqlite:/tmp/regal.sqlite'` anlegen.

Es gibt keinen Build-Schritt und keine Abhängigkeiten zur Laufzeit. Das ist kein
Purismus, sondern folgt aus dem Hosting: ohne Shell-Zugang gibt es keinen Composer
auf dem Server. Was an Bibliotheken gebraucht wird, liegt fertig in `public/js/`.

### Was beim Ändern zu beachten ist

- **Nichts von fremden Servern nachladen.** Keine CDN, keine Web-Fonts, keine
  Analyse. Daran hängt mehr als Geschmack: die strenge Content-Security-Policy und
  die Tatsache, dass die Seite ohne Cookie-Banner auskommt. Die erste externe
  Ressource kostet beides.
- **Sämtlicher Datenbankzugriff gehört in `app/Repository/`.** Templates enthalten
  keine Logik über Schleifen hinaus.
- **`owner_id` filtert immer mit**, auch solange es nur eine Sammlung gibt.

## Deployment

Über GitHub Actions (*Actions → Deploy zu all-inkl → Run workflow*), nicht
automatisch bei jedem Commit. Nötige Secrets: `FTP_SERVER`, `FTP_USERNAME`,
`FTP_PASSWORD`.

Hochgeladen wird das Repo-Wurzelverzeichnis in den Projektordner. Nie angefasst
werden `config.php`, `storage/` und `public/covers/` — das sind die selbst
aufgenommenen Coverfotos, die es nur auf dem Server gibt.

## Datenquellen

| Quelle | Wofür | Bedingungen |
|---|---|---|
| [DNB](https://services.dnb.de/sru/dnb) | deutsche Titel | Metadaten CC0, kein Schlüssel nötig |
| [Google Books](https://developers.google.com/books) | englische Titel, Cover | ~1.000 Abfragen am Tag, Schlüssel kostenlos |
| [Open Library](https://openlibrary.org/developers/api) | englische Titel, Cover | offen, Rücklink erwünscht |

Cover werden **heruntergeladen und selbst ausgeliefert**, nicht eingebettet. So
entsteht beim Betrachten des Regals keine Verbindung zu Dritten. Quelle und
Rücklink werden je Bild gespeichert und angezeigt.

## Lizenz

Quellcode MIT. Logo, Schriften und Coverbilder sind ausgenommen und gehören ihren
Rechteinhabern — siehe [LICENSE](LICENSE). Wer eine eigene Installation betreibt,
sollte die Dateien in `public/assets/` durch eigene ersetzen.
