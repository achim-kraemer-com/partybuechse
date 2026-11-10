<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DJ-Modus: Die Partybüchse startet jeden Titel selbst, statt Spotifys Warteschlange zu füllen.
 *
 * Damit verschwindet der Status "queued" ("an Spotify übergeben") und mit ihm die Spalte
 * queued_at; an ihre Stelle tritt started_at ("von der Büchse gestartet"). Zeilen, die noch auf
 * "queued" stehen, stammen aus der alten Mechanik: Was dort in Spotifys Warteschlange lag, ist
 * längst gelaufen oder verfallen, es gilt deshalb als gespielt.
 *
 * Außerdem bleibt pro Spotify-Konto nur noch eine Party aktiv (die zuletzt gestartete). Liefen
 * zwei, griffen beide auf denselben Player zu.
 */
final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DJ-Modus: suggestion.started_at statt queued_at, Status "queued" aufgelöst, eine aktive Party pro Host';
    }

    public function up(Schema $schema): void
    {
        $suggestion = $schema->getTable('suggestion');
        $suggestion->addColumn('started_at', 'datetime_immutable', ['notnull' => false]);
        $suggestion->dropColumn('queued_at');

        $this->addSql("UPDATE suggestion SET status = 'played' WHERE status = 'queued'");

        // Nur die jüngste aktive Party pro Host überlebt. Die abgeleitete Tabelle ist nötig,
        // weil MySQL dieselbe Tabelle nicht direkt in der Unterabfrage eines UPDATE duldet.
        // 0 und 1 für active: so schreibt es SQLite ebenso wie MySQL.
        $this->addSql(<<<'SQL'
            UPDATE party
               SET active = 0,
                   sync_status = 'ended',
                   ended_at = CURRENT_TIMESTAMP
             WHERE active = 1
               AND id NOT IN (
                   SELECT id FROM (
                       SELECT MAX(id) AS id FROM party WHERE active = 1 GROUP BY host_id
                   ) AS neueste
               )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $suggestion = $schema->getTable('suggestion');
        $suggestion->addColumn('queued_at', 'datetime_immutable', ['notnull' => false]);
        $suggestion->dropColumn('started_at');
    }
}
