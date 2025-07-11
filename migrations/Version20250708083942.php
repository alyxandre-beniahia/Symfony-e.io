<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250708083942 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE posts (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, parent_post_id INT DEFAULT NULL, content LONGTEXT NOT NULL, type VARCHAR(20) NOT NULL, language VARCHAR(10) DEFAULT 'fr' NOT NULL, likes_count INT DEFAULT 0 NOT NULL, retweets_count INT DEFAULT 0 NOT NULL, views_count INT DEFAULT 0 NOT NULL, impressions_count INT DEFAULT 0 NOT NULL, clicks_count INT DEFAULT 0 NOT NULL, engagement_score DOUBLE PRECISION DEFAULT '0' NOT NULL, is_pinned TINYINT(1) DEFAULT 0 NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, INDEX IDX_885DBAFAA76ED395 (user_id), INDEX IDX_885DBAFA39C1776A (parent_post_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE posts ADD CONSTRAINT FK_885DBAFAA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE posts ADD CONSTRAINT FK_885DBAFA39C1776A FOREIGN KEY (parent_post_id) REFERENCES posts (id)
        SQL);
        // Les index sur la table user existent déjà, pas besoin de les recréer
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE posts DROP FOREIGN KEY FK_885DBAFAA76ED395
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE posts DROP FOREIGN KEY FK_885DBAFA39C1776A
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE posts
        SQL);
        // Les index sur la table user ne doivent pas être supprimés
    }
}
