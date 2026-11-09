# Partybüchse

Gäste scannen einen QR-Code, wünschen sich Songs aus dem Spotify-Katalog, bewerten die Wünsche der anderen, und der bestbewertete Titel wandert automatisch in die Spotify-Warteschlange des Hosts.

- Jeder Gast hat **3 Stimmen** (einstellbar). Ein eigener Wunsch kostet eine Stimme, Stimmen für fremde Wünsche ebenfalls.
- Sobald ein Wunsch gelaufen ist, bekommen alle Wähler ihre Stimme zurück.
- Gäste brauchen **keine App und keinen Account**. Der Host-Bereich liegt hinter einer Login-Maske; nur der Host verbindet sein Spotify-Premium-Konto.
- Die Musik läuft über den normalen Spotify-Client des Hosts (Spotify Connect). Was der Host selbst abspielt, dient als natürlicher Fallback, Wünsche werden kurz vor Ende des laufenden Titels eingereiht.

Symfony 7.4 · PHP ≥ 8.2 · SQLite · kein Build-Schritt im Frontend · Docker-Image für den Server.

## 1. Spotify-App anlegen

1. <https://developer.spotify.com/dashboard> → **Create app**.
2. *Redirect URI*: lokal `http://127.0.0.1:8000/spotify/callback` (Spotify akzeptiert kein `localhost`, nur die Loopback-IP), auf dem Server `https://deine-domain.de/spotify/callback`. Beide können gleichzeitig eingetragen sein.
3. *Which API/SDKs are you planning to use?* → **Web API**.
4. Client-ID und Client-Secret kopieren.

Hinweise zum aktuellen Development Mode von Spotify (Stand 2026):

- Der App-Inhaber braucht **Spotify Premium**, sonst funktioniert die Web API gar nicht.
- Nur das eigene Konto (plus max. 4 weitere in der Allowlist) darf sich bei Spotify anmelden. Für die Partybüchse reicht das: **nur der Host verbindet Spotify**, Gäste sprechen nie mit Spotify.
- Suchergebnisse sind auf 10 pro Anfrage begrenzt; die App cached Suchen für zwei Minuten.

## 2. Konfiguration (`.env`)

Im Repository liegt als Env-Vorlage nur `.env.dist` – mit Platzhaltern, ohne Zugangsdaten. Die eigene Konfiguration ist eine Kopie davon und bleibt lokal, denn `.env` und `.env.local` sind von Git ignoriert:

```bash
cp .env.dist .env
```

Mindestens diese Werte gehören dort hinein:

```dotenv
APP_SECRET=ein-langer-zufaelliger-string

# Host-Login (Login-Maske unter /login)
HOST_USERNAME=achim
HOST_PASSWORD=bitte-aendern
# oder statt HOST_PASSWORD ein Hash (in einfache Anführungszeichen wegen der $-Zeichen):
# HOST_PASSWORD_HASH='$2y$13$…'      → erzeugen mit: php bin/console security:hash-password

# Spotify
SPOTIFY_CLIENT_ID=…
SPOTIFY_CLIENT_SECRET=…
SPOTIFY_REDIRECT_URI=http://127.0.0.1:8000/spotify/callback

# Adresse, die im QR-Code landet (leer = Host der aktuellen Anfrage)
PUBLIC_BASE_URL=
```

## 3. Lokal ohne Docker

```bash
composer install
composer setup            # legt var/data.db an und führt die Migration aus
symfony serve --port=8000 # oder: php -S 127.0.0.1:8000 -t public
```

<http://127.0.0.1:8000> → **Host-Login** → **Mit Spotify verbinden** → Party anlegen.

## 4. Docker

Zum Testen und für den gehosteten Server gibt es ein Image (Apache + PHP 8.3, SQLite in einem Volume). Compose liest `.env` und `.env.local` ein, es sind also dieselben Dateien wie ohne Docker.

```bash
docker compose up --build -d        # http://127.0.0.1:8000
docker compose logs -f app          # Entrypoint: Cache, Migration, Warnungen bei fehlender Konfiguration
docker compose down                 # Daten bleiben im Volume "db-data"
```

Entwicklungsbuild mit Profiler und ausführlichen Fehlern:

```bash
DOCKER_APP_ENV=dev DOCKER_APP_DEBUG=1 docker compose up --build
```

Nützliches:

```bash
docker compose exec app php bin/console app:party:sync --watch   # optionaler Worker
docker compose exec app php bin/console security:hash-password   # Passwort-Hash erzeugen
docker compose exec app php bin/phpunit                          # Tests im Dev-Build
```

### Auf dem Server (HTTPS)

Das Image spricht nur HTTP auf Port 80. Davor gehört ein Reverse Proxy mit TLS, z. B. Caddy:

```
partybuechse.example.de {
    reverse_proxy partybuechse:80
}
```

Dann in `.env` auf dem Server:

```dotenv
SPOTIFY_REDIRECT_URI=https://partybuechse.example.de/spotify/callback
PUBLIC_BASE_URL=https://partybuechse.example.de
TRUSTED_PROXIES=172.16.0.0/12      # Docker-Netz des Proxys, damit https/Host korrekt erkannt werden
```

Compose braucht Version 2.24 oder neuer (für `env_file` mit `required: false`).

## 5. Party-Betrieb

1. Spotify auf dem Gerät starten, das an den Boxen hängt (Laptop, Handy, Sonos, …), und irgendeine Playlist abspielen.
2. Den **Display-Link** (Host-Ansicht → „Display öffnen“) auf Beamer oder Tablet zeigen. Dort stehen QR-Code, Code, aktueller Titel und die Wunschliste. Der Link enthält einen Schlüssel und funktioniert ohne Login.
3. Gäste scannen, wünschen, stimmen ab.

Lokal im WLAN muss die App für die Handys erreichbar sein:

```bash
symfony serve --port=8000 --allow-all-ip      # oder php -S 0.0.0.0:8000 -t public
```

und `PUBLIC_BASE_URL=http://192.168.178.20:8000` (deine LAN-Adresse) setzen. Die Spotify-Verbindung läuft weiter über `http://127.0.0.1:8000`, also die Host-Ansicht auf dem Rechner öffnen, auf dem die App läuft. Auf dem gehosteten Server entfällt das alles.

### Als App installieren

Die Seite ist eine PWA: In Chrome erscheint in der Adressleiste ein **Installieren**-Knopf
(oder ⋮ → *Streamen, speichern und teilen* → *Seite als App installieren*), auf dem iPhone
geht es über *Teilen* → *Zum Home-Bildschirm*. Die App startet dann ohne Adressleiste in
einem eigenen Fenster, mit eigenem Icon im Dock bzw. auf dem Home-Bildschirm.

Dazu gehören `public/manifest.webmanifest`, die Icons in `public/icons/` und
`public/sw.js`. Der Service Worker speichert bewusst fast nichts zwischen – er arbeitet
"erst das Netz, dann der Zwischenspeicher" – und dient nur zwei Zwecken: Chrome bietet das
Installieren ohne ihn nicht an, und ohne Netz erscheint `public/offline.html` statt der
Fehlerseite des Browsers. Offline nutzbar ist die Partybüchse nicht, Wunschliste und
Spotify liegen auf dem Server.

Ändert sich eine der Dateien, die `sw.js` unter `SHELL` vorhält, gehört `VERSION` darin
hochgezählt. `tests/WebApp/InstallableAppTest.php` prüft Manifest, Icon-Größen und dass
jede vorgehaltene Datei existiert.

**Eine Party zur Zeit.** Ein Spotify-Konto hat genau einen Player, also steuert ihn genau eine Party: die zuletzt gestartete. Eine neue Party beendet deshalb die laufende, und eine alte lässt sich nicht wieder öffnen, solange eine andere läuft. Andere aktive Partys desselben Hosts gehen auf `standby` und fassen Spotify nicht an – liefen zwei gleichzeitig, verbuchten beide den laufenden Titel als ihren eigenen und legten beide Wünsche in dieselbe Warteschlange.

Ohne Hintergrundprozess: Jede offene Seite (Gast, Display, Host) stößt alle paar Sekunden den Abgleich mit Spotify an. Läuft keine Seite, kann optional `php bin/console app:party:sync --watch` übernehmen.

## 6. Wie der Abgleich funktioniert

`App\Service\PlaybackSync` läuft höchstens alle 4 Sekunden pro Party:

1. Playback-Status vom Host-Konto lesen und als „Läuft gerade“ speichern.
2. Wünsche, die an Spotify übergeben wurden, abschließen: läuft der Titel → `played` (Stimmen frei); ist er aus der Warteschlange verschwunden → ebenfalls als erledigt markieren (verhindert Endlosschleifen, wenn der Host skippt).
3. Wartet noch keiner unserer Wünsche in Spotify: den Top-Wunsch **≤ 20 s vor Ende** des laufenden Titels in die Warteschlange legen. Ist der Player untätig, sofort starten.
4. Keine Wünsche und Player untätig: Fallback-Playlist starten (falls konfiguriert).

Ein pausierter Player wird nicht angefasst.

## 7. Sicherheit

- `/host/*` und `/spotify/*` verlangen den Host-Login (Symfony Security, Formular-Login mit CSRF, Remember-me, max. 5 Fehlversuche pro 15 Minuten).
- Gäste werden über ein zufälliges Cookie pro Browser erkannt; JSON-Aufrufe verlangen den Header `X-Requested-With: fetch`.
- Das Display ist über einen zufälligen Schlüssel in der URL geschützt, damit es auf einem TV ohne Login laufen kann.
- Spotify-Tokens liegen nur in der SQLite-Datenbank des Hosts.

## 8. Tests

```bash
composer test     # = php bin/phpunit
```

Ohne lokales PHP geht es auch mit Docker, ganz ohne die Dev-Umgebung einzurichten:

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli sh -c 'php vendor/bin/phpunit'
```

(Beim ersten Mal davor einmal `composer install` im selben Container, siehe Abschnitt 4.)

Die Test-Umgebung braucht keine eigene Env-Datei: Kernel-Klasse, Test-Datenbank und Dummy-Zugangsdaten stehen mit `force="true"` in `phpunit.dist.xml`, damit eine lokale `.env` keine echten Credentials in den Testlauf trägt.

`tests/Service/PlaybackSyncTest.php` deckt die Sync-Entscheidungen mit einem Fake-Spotify ab, `VotingServiceTest.php` die Stimmenregeln, `Controller/LoginTest.php` den Host-Login, `Controller/SmokeTest.php` die Seiten.

## 9. Struktur

```
src/Controller      Home, Login, Spotify-Verbindung, Host, Gast, Display, QR, JSON-API
src/Entity          Host, Party, Guest, Suggestion (+Status), Vote
src/Security        HostUser, HostUserProvider, HostLoginAuthenticator, HostCredentials (aus Env)
src/Service         VotingService, PlaybackSync, TrackLookup, GuestResolver, HostSession, …
src/Spotify         SpotifyClient (Web API), SpotifyAuthenticator (OAuth), HostTokenProvider, DTOs
templates/          Twig, Design angelehnt an achim-kraemer.com (Lexend, dunkel, Violett/Blau)
public/css, public/js  Plain CSS und Vanilla JS, kein Build
migrations/         Schema über die Doctrine Schema API (SQLite/MySQL/PostgreSQL)
docker/, Dockerfile, compose.yaml   Container für Test und Hosting
```

## 10. Bekannte Grenzen

- Spotify kann die Web API jederzeit weiter einschränken (zuletzt 11/2024, 05/2025, 02/2026). Der Development Mode ist für private Nutzung gedacht; für einen Dauerbetrieb in einer Bar wäre Extended Quota nötig, das Spotify nur Unternehmen mit 250.000 MAU gewährt.
- Die Warteschlange ist Spotify-seitig: Hat der Host selbst Titel eingereiht, kommen die zuerst.
- Episoden/Podcasts werden angezeigt, aber nicht als Wunsch angeboten.
- Es gibt genau ein Host-Konto (aus der Umgebung). Mehrere Hosts bräuchten eine Benutzertabelle.

## 11. Deployment

Zwei Remotes, wie bei den Schwesterprojekten:

| Remote   | Zweck |
|----------|-------|
| `origin` | GitHub – Versionsstand, deployt nichts |
| `main`   | Server – ein Push löst die Auslieferung aus |

```bash
git push origin main    # nur versionieren
git push main main      # ausliefern
```

Das Server-Remote ist
`ssh://achim-kraemer.com@achim-kraemer.com:/var/www/vhosts/achim-kraemer.com/partybuechse.achim-kraemer.com.git`.
Dessen `post-receive`-Hook checkt `main` nach
`/var/www/vhosts/achim-kraemer.com/partybuechse.achim-kraemer.com` aus und ruft
danach `deploy.sh` auf: Abhängigkeiten installieren, Datenbank anlegen und
migrieren, Cache aufbauen. Der Hook liegt auf dem Server und ist **nicht
versioniert**; Änderungen daran müssen dort direkt gemacht werden.

`deploy.sh` legt die PHP-Version ausdrücklich auf `/opt/plesk/php/8.5/bin/php`
fest. Ein Git-Hook läuft in einer nicht-interaktiven Shell, in der `php` die
Systemversion 8.3 ist – das Web der Subdomain läuft aber unter 8.5. Liefen
beide auseinander, baute 8.3 den Container nach `var/cache/prod` und 8.5 läse
ihn wieder aus. Aus demselben Grund wird `composer` über die
Plesk-`composer.phar` aufgerufen.

`composer.lock` und `symfony.lock` sind versioniert, und das ist nicht nur
Hygiene: ohne sie löst `composer install` auf dem Server eine vollständige
Auflösung aus, und Symfony Flex rollt dabei **alle Rezepte neu aus**. Beim
ersten Deploy hat das eine `.env` mit `DATABASE_URL="postgresql://…"` angelegt,
die `.env.dist` verdeckt hat – die Anwendung lief danach gegen einen
PostgreSQL-Server, den es hier nicht gibt. Mit den beiden Lock-Dateien
installiert der Deploy feste Fassungen und rührt keine Rezepte an.

`config.platform.php` in `composer.json` steht auf `8.2.0`, also auf dem
Mindestwert, den das Projekt selbst verlangt. Ohne diese Angabe löst composer
gegen die PHP-Fassung der Maschine auf, auf der es läuft – auf dem Server ist
das 8.5, und dabei entsteht ein Lock, der sich im Docker-Image (8.3) nicht
installieren lässt. Mit der Angabe passt derselbe Lock auf 8.2 bis 8.5.

Die Datenbank ist eine SQLite-Datei in `var/` und liegt damit außerhalb der
Dokumentwurzel. Sie überlebt den Deploy, weil der Hook kein `git clean` läuft –
`.env.local`, `var/` und `vendor/` sind nicht getrackt.

### Einmalige Einrichtung auf dem Server

1. **Document-Root in Plesk** auf `partybuechse.achim-kraemer.com/public`
   setzen. Zeigt sie auf den Projektordner, ist der ganze Quellbaum samt
   `.env.local` und der SQLite-Datenbank abrufbar: unter nginx/Plesk wird
   `.htaccess` **vollständig ignoriert**, es gibt dort also keinen zweiten
   Schutz. **Danach nachmessen, nicht annehmen** – eine Markierungsdatei in
   den Projektordner legen und prüfen, dass sie von außen nicht kommt:

   ```bash
   curl -sS -o /dev/null -w '%{http_code}\n' https://partybuechse.achim-kraemer.com/.env.dist
   # 404 ist richtig. 200 bedeutet: Dokumentwurzel falsch, nicht deployen.
   ```

2. **Umgebung** einrichten. Die Grundwerte stehen in der versionierten
   `.env.dist`; eigene Werte gehören in eine ungetrackte `.env.local`, die
   jene überschreibt. Ein `cp` ist nicht nötig – Symfony lädt `.env.dist`
   automatisch, wenn keine `.env` existiert.

   ```bash
   cd /var/www/vhosts/achim-kraemer.com/partybuechse.achim-kraemer.com

   # APP_SECRET erzeugen
   /opt/plesk/php/8.5/bin/php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'

   # Host-Passwort hashen – fragt das Passwort verdeckt ab, es landet weder in
   # der Shell-History noch in der Prozessliste
   /opt/plesk/php/8.5/bin/php bin/console security:hash-password

   cat > .env.local <<'ENV'
   APP_ENV=prod
   APP_SECRET=<Ausgabe von oben>
   HOST_USERNAME=achim
   HOST_PASSWORD_HASH='<Hash von oben, einfache Anführungszeichen wegen der $-Zeichen>'
   SPOTIFY_CLIENT_ID=<Spotify Developer Dashboard>
   SPOTIFY_CLIENT_SECRET=<Spotify Developer Dashboard>
   SPOTIFY_REDIRECT_URI=https://partybuechse.achim-kraemer.com/spotify/callback
   PUBLIC_BASE_URL=https://partybuechse.achim-kraemer.com
   ENV
   chmod 600 .env.local
   ```

   Statt `HOST_PASSWORD_HASH` tut es auch `HOST_PASSWORD` im Klartext; steht
   beides, gewinnt der Hash. Beide Werte liest Symfony bei jedem Request neu –
   ein erneutes Deployment ist nach einer Änderung an `.env.local` nicht nötig,
   solange keine `.env.local.php` existiert.

   `deploy.sh` bricht ab, solange `.env.local` fehlt oder darin noch der
   `APP_SECRET`-Platzhalter aus `.env.dist` steht – der Wert ist über das
   öffentliche Repository bekannt und signiert CSRF-Token und das
   Remember-Me-Cookie des Host-Logins.

3. **Redirect-URI bei Spotify eintragen.** Im Developer Dashboard muss
   `https://partybuechse.achim-kraemer.com/spotify/callback` als Redirect URI
   stehen, zusätzlich zur lokalen. Fehlt sie, bricht die Anmeldung mit
   `INVALID_CLIENT: Invalid redirect URI` ab.

4. `git push main main` – jetzt läuft das Deployment durch.

### Was tun, wenn im Betrieb etwas schiefgeht

Die Anwendung schreibt im Prod-Betrieb zwei Dateien nach `var/log/` – ausserhalb
der Dokumentwurzel, also nicht über das Web erreichbar:

```bash
ssh <host> 'cd <projekt> && tail -n 40 var/log/prod.error-*.log'   # jeder 500er, mit Stacktrace
ssh <host> 'cd <projekt> && tail -f  var/log/prod-*.log'           # Mitschnitt: Anmeldungen, Spotify-Übergaben, Sync
```

Die beiden Dateien sind getrennt, weil der Mitschnitt im Partybetrieb schnell
auf tausende Zeilen wächst und ein einzelner Fehler darin untergeht. Symfony
protokolliert nicht abgefangene Ausnahmen im Kanal `request`; der ist aus dem
Mitschnitt ausgeschlossen, landet aber vollständig in der Fehlerdatei.

Unter Plesk hilft `php://stderr` nicht weiter: das führt in ein PHP-FPM-Log
unter `/var/www/vhosts/system/<domain>/logs/`, das nur root lesen kann. Im
Plesk-Panel ist es über **Websites & Domains → Protokolle** einsehbar.
