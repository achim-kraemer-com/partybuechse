<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema. Written against the Schema API so it works on SQLite as well as MySQL/PostgreSQL.
 */
final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: host, party, guest, suggestion, vote';
    }

    public function up(Schema $schema): void
    {
        $host = $schema->createTable('host');
        $host->addColumn('id', 'integer', ['autoincrement' => true]);
        $host->addColumn('spotify_user_id', 'string', ['length' => 255]);
        $host->addColumn('display_name', 'string', ['length' => 255, 'notnull' => false]);
        $host->addColumn('access_token', 'text');
        $host->addColumn('refresh_token', 'text');
        $host->addColumn('token_expires_at', 'datetime_immutable');
        $host->addColumn('created_at', 'datetime_immutable');
        $host->setPrimaryKey(['id']);
        $host->addUniqueIndex(['spotify_user_id'], 'uniq_host_spotify_user');

        $party = $schema->createTable('party');
        $party->addColumn('id', 'integer', ['autoincrement' => true]);
        $party->addColumn('host_id', 'integer');
        $party->addColumn('code', 'string', ['length' => 8]);
        $party->addColumn('display_key', 'string', ['length' => 32]);
        $party->addColumn('name', 'string', ['length' => 80]);
        $party->addColumn('votes_per_guest', 'integer');
        $party->addColumn('active', 'boolean');
        $party->addColumn('fallback_playlist_uri', 'string', ['length' => 255, 'notnull' => false]);
        $party->addColumn('now_playing', 'json', ['notnull' => false]);
        $party->addColumn('sync_status', 'string', ['length' => 32]);
        $party->addColumn('sync_message', 'string', ['length' => 255, 'notnull' => false]);
        $party->addColumn('last_sync_at', 'datetime_immutable', ['notnull' => false]);
        $party->addColumn('created_at', 'datetime_immutable');
        $party->addColumn('ended_at', 'datetime_immutable', ['notnull' => false]);
        $party->setPrimaryKey(['id']);
        $party->addUniqueIndex(['code'], 'uniq_party_code');
        $party->addIndex(['host_id'], 'idx_party_host');
        $party->addForeignKeyConstraint('host', ['host_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_party_host');

        $guest = $schema->createTable('guest');
        $guest->addColumn('id', 'integer', ['autoincrement' => true]);
        $guest->addColumn('party_id', 'integer');
        $guest->addColumn('token', 'string', ['length' => 64]);
        $guest->addColumn('nickname', 'string', ['length' => 40, 'notnull' => false]);
        $guest->addColumn('created_at', 'datetime_immutable');
        $guest->setPrimaryKey(['id']);
        $guest->addUniqueIndex(['party_id', 'token'], 'uniq_guest_party_token');
        $guest->addForeignKeyConstraint('party', ['party_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_guest_party');

        $suggestion = $schema->createTable('suggestion');
        $suggestion->addColumn('id', 'integer', ['autoincrement' => true]);
        $suggestion->addColumn('party_id', 'integer');
        $suggestion->addColumn('suggested_by_id', 'integer', ['notnull' => false]);
        $suggestion->addColumn('spotify_uri', 'string', ['length' => 100]);
        $suggestion->addColumn('title', 'string', ['length' => 255]);
        $suggestion->addColumn('artists', 'string', ['length' => 255]);
        $suggestion->addColumn('album', 'string', ['length' => 255, 'notnull' => false]);
        $suggestion->addColumn('image_url', 'string', ['length' => 500, 'notnull' => false]);
        $suggestion->addColumn('duration_ms', 'integer');
        $suggestion->addColumn('vote_count', 'integer');
        $suggestion->addColumn('status', 'string', ['length' => 16]);
        $suggestion->addColumn('created_at', 'datetime_immutable');
        $suggestion->addColumn('queued_at', 'datetime_immutable', ['notnull' => false]);
        $suggestion->addColumn('played_at', 'datetime_immutable', ['notnull' => false]);
        $suggestion->setPrimaryKey(['id']);
        $suggestion->addIndex(['party_id', 'status'], 'idx_suggestion_party_status');
        $suggestion->addIndex(['suggested_by_id'], 'idx_suggestion_suggested_by');
        $suggestion->addForeignKeyConstraint('party', ['party_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_suggestion_party');
        $suggestion->addForeignKeyConstraint('guest', ['suggested_by_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_suggestion_guest');

        $vote = $schema->createTable('vote');
        $vote->addColumn('id', 'integer', ['autoincrement' => true]);
        $vote->addColumn('suggestion_id', 'integer');
        $vote->addColumn('guest_id', 'integer');
        $vote->addColumn('created_at', 'datetime_immutable');
        $vote->setPrimaryKey(['id']);
        $vote->addUniqueIndex(['suggestion_id', 'guest_id'], 'uniq_vote_suggestion_guest');
        $vote->addIndex(['guest_id'], 'idx_vote_guest');
        $vote->addForeignKeyConstraint('suggestion', ['suggestion_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_vote_suggestion');
        $vote->addForeignKeyConstraint('guest', ['guest_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_vote_guest');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('vote');
        $schema->dropTable('suggestion');
        $schema->dropTable('guest');
        $schema->dropTable('party');
        $schema->dropTable('host');
    }
}
