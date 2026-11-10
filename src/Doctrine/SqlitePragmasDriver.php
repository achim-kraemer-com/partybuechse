<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * @see SqlitePragmasMiddleware
 */
final class SqlitePragmasDriver extends AbstractDriverMiddleware
{
    /** @var string[] */
    private const PRAGMAS = [
        // Lesen blockiert Schreiben nicht mehr und umgekehrt.
        'PRAGMA journal_mode = WAL',
        // Lieber fünf Sekunden warten als mit "database is locked" abbrechen.
        'PRAGMA busy_timeout = 5000',
        // Mit WAL reicht das: ein Absturz kostet höchstens die letzte Transaktion,
        // ein Stromausfall beschädigt die Datei nicht.
        'PRAGMA synchronous = NORMAL',
    ];

    /**
     * {@inheritDoc}
     */
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): DriverConnection {
        $connection = parent::connect($params);

        if (!str_contains((string) ($params['driver'] ?? ''), 'sqlite')) {
            return $connection;
        }

        foreach (self::PRAGMAS as $pragma) {
            try {
                $connection->exec($pragma);
            } catch (DriverException) {
                // Eine Datenbank im Speicher kennt kein WAL, und ein Hoster kann PRAGMA
                // verbieten. Beides ist kein Grund, die Anwendung nicht zu starten.
            }
        }

        return $connection;
    }
}
