<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * suggestion.confirmed_at: wann der Player einen gestarteten Wunsch wirklich gespielt hat.
 *
 * Spotify quittiert PUT /me/player/play sofort und meldet über GET /me/player noch eine Weile
 * den vorherigen Titel – manchmal spielt es den Wunsch auch gar nicht (schlafendes Gerät). Ohne
 * diesen Vermerk hielt der nächste Durchgang den gerade gestarteten Wunsch für durchgelaufen,
 * hakte ihn ab und startete den nächsten: In vier Sekunden war die Wunschliste leer und zu hören
 * war nichts.
 *
 * Für Wünsche, die beim Deploy gerade laufen, gilt der Start als bestätigt: Sie liefen bereits,
 * bevor es diese Spalte gab.
 */
final class Version20261111190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'suggestion.confirmed_at: bestätigt, dass der Player den gestarteten Wunsch spielt';
    }

    public function up(Schema $schema): void
    {
        // Von Hand statt über $schema: Die Anweisungen aus addSql() laufen vor den
        // Schema-Änderungen, ein UPDATE auf die neue Spalte käme dort zu früh. ADD COLUMN mit
        // DATETIME versteht SQLite wie MySQL.
        $this->addSql('ALTER TABLE suggestion ADD COLUMN confirmed_at DATETIME DEFAULT NULL');
        $this->addSql("UPDATE suggestion SET confirmed_at = started_at WHERE status = 'playing' AND started_at IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('suggestion')->dropColumn('confirmed_at');
    }
}
