#!/usr/bin/env bash
#
# Wird vom post-receive-Hook auf dem Server nach jedem Push ausgeführt.
# Liegt im Projektwurzelverzeichnis und wird von dort aus aufgerufen.

set -euo pipefail

# Ein Git-Hook läuft in einer nicht-interaktiven Shell. Dort ist "php" die
# Systemversion 8.3, das Web der Subdomain läuft aber unter 8.5. Liefen beide
# auseinander, baute 8.3 den Container nach var/cache/prod und 8.5 läse ihn
# wieder aus. Deshalb wird die PHP-Version des Vhosts hier ausdrücklich
# festgelegt, statt sich auf die PATH-Auflösung zu verlassen.
PHP="/opt/plesk/php/8.5/bin/php"
COMPOSER="/opt/psa/var/modules/composer/composer.phar"

cd "$(dirname "$0")"

for binary in "$PHP" "$COMPOSER"; do
    if [ ! -e "$binary" ]; then
        echo "FEHLER: $binary wurde nicht gefunden. Pfad in deploy.sh anpassen." >&2
        exit 1
    fi
done

# .env.dist liegt im Repository und wird von Symfony als Rückfall geladen, wenn
# keine .env existiert. Die echten Werte gehören in eine ungetrackte .env.local.
if [ ! -f .env.local ] && [ ! -f .env.local.php ]; then
    cat >&2 <<'HINWEIS'
FEHLER: Es fehlt die .env.local mit den Werten dieser Installation.

  cat > .env.local <<'ENV'
  APP_ENV=prod
  APP_SECRET=<32 zufällige Zeichen>
  HOST_USERNAME=<Benutzername für /login>
  HOST_PASSWORD_HASH='<Hash, siehe unten>'
  SPOTIFY_CLIENT_ID=<aus dem Spotify Developer Dashboard>
  SPOTIFY_CLIENT_SECRET=<aus dem Spotify Developer Dashboard>
  SPOTIFY_REDIRECT_URI=https://partybuechse.achim-kraemer.com/spotify/callback
  PUBLIC_BASE_URL=https://partybuechse.achim-kraemer.com
  ENV

APP_SECRET erzeugen (läuft ohne fertige Anwendung):

  /opt/plesk/php/8.5/bin/php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'

Passwort-Hash erzeugen (nach dem ersten erfolgreichen Deploy):

  /opt/plesk/php/8.5/bin/php bin/console security:hash-password
HINWEIS
    exit 1
fi

# Der Platzhalter aus .env.dist steht im öffentlichen Repository. Liefe damit
# die Produktion, wären CSRF-Token und Anmelde-Cookies mit einem bekannten
# Schlüssel signiert. Deshalb hier hart abbrechen.
for datei in .env.local .env.local.php; do
    [ -f "$datei" ] || continue
    if grep -q 'change-me-in-env-local-5f2b9c1e7a4d' "$datei"; then
        cat >&2 <<HINWEIS
FEHLER: In $datei steht noch der APP_SECRET-Platzhalter aus .env.dist.

Der Wert ist über das öffentliche Repository bekannt; er signiert CSRF-Token
und das Remember-Me-Cookie des Host-Logins. Echten Wert erzeugen:

  $PHP -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
HINWEIS
        exit 1
    fi
done

echo "→ Abhängigkeiten installieren"
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress

# Die SQLite-Datei liegt in var/ und damit außerhalb der Dokumentwurzel. Beim
# ersten Deploy existiert sie noch nicht; PDO legt sie beim Verbinden an,
# sobald das Verzeichnis da ist. Kein "doctrine:database:create": DBAL 4 kann
# getListDatabasesSQL für SQLite nicht und bricht mit NotSupported ab.
mkdir -p var var/log

echo "→ Datenbank migrieren"
"$PHP" bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "→ Cache aufbauen"
"$PHP" bin/console cache:clear

echo "✓ Deployment abgeschlossen"
