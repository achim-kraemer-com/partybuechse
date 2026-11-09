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

## 2. Konfiguration (`.env.local`)

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

Zum Testen und für den gehosteten Server gibt es ein Image (Apache + PHP 8.3, SQLite in einem Volume). Compose liest deine `.env.local` ein, es ist also dieselbe Datei wie ohne Docker.

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

Dann in `.env.local` auf dem Server:

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
