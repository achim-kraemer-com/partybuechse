<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Stellt jede SQLite-Verbindung auf WAL und eine Wartezeit bei Sperren um.
 *
 * Eine Party ist nebenläufig: Jedes Handy pollt, der Hintergrunddienst gleicht ab, der Host
 * klickt. SQLite sperrt im Standard-Journal die ganze Datei und bricht einen zweiten Schreiber
 * sofort mit „database is locked“ ab – mitten im Abgleich, nachdem Spotify schon etwas gesagt
 * bekommen hat. Mit WAL behindern sich Lesen und Schreiben nicht mehr, und busy_timeout lässt
 * einen Schreiber warten statt aufzugeben.
 *
 * Das ist der Grund, warum es für die Partybüchse keine MySQL-Datenbank braucht: Die Last sind
 * ein paar Dutzend Zeilen und wenige Schreibvorgänge pro Sekunde; was fehlte, war nicht ein
 * größerer Server, sondern diese zwei Einstellungen.
 */
final class SqlitePragmasMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new SqlitePragmasDriver($driver);
    }
}
