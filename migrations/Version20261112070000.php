<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Bremse: party.spotify_quiet_until und party.failed_starts.
 *
 * In der Nacht zum 12.11.2026 standen 18 402 Zeilen „Too many requests" im Log – rund eine pro
 * Sekunde über fünfeinhalb Stunden. Der Grund: Ein Wunsch, den der Player nicht annahm, ging
 * zurück in die Rangliste und wurde zehn Sekunden später erneut gestartet, dazu der Abgleich
 * alle zwei Sekunden. Spotify sperrte daraufhin das Kontingent der App.
 *
 * Mit failed_starts zählt die Büchse die Fehlversuche und hört nach dreien auf; in
 * spotify_quiet_until steht, bis wann der Player in Ruhe gelassen wird – nach einer Drosselung
 * so lange, wie Spotify es im Retry-After verlangt.
 */
final class Version20261112070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'party.spotify_quiet_until und party.failed_starts: Pause nach Drosselung und Grenze für Startversuche';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE party ADD COLUMN spotify_quiet_until DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE party ADD COLUMN failed_starts INTEGER NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $party = $schema->getTable('party');
        $party->dropColumn('spotify_quiet_until');
        $party->dropColumn('failed_starts');
    }
}
