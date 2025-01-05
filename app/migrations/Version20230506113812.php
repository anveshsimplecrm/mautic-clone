<?php

declare(strict_types=1);

namespace Mautic\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Doctrine\PreUpAssertionMigration;

final class Version20230506113812 extends PreUpAssertionMigration
{
    public function preUpAssertions(): void
    {
        $this->skipAssertion(function (Schema $schema) {
            return $schema->getTable($this->getTableName())->hasIndex($this->getIndexName());
        }, sprintf('The index "%s" has already been added to the table "%s".', $this->getIndexName(), $this->getTableName()));
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf('CREATE INDEX %s ON %s (date_added)', $this->getIndexName(), $this->getTableName()));
    }

    private function getTableName(): string
    {
        return "{$this->prefix}lead_utmtags";
    }

    private function getIndexName(): string
    {
        return "{$this->prefix}utm_date_added";
    }
}
