<?php

namespace justinholtweb\peek\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%peek_release_entries}}');
        $this->dropTableIfExists('{{%peek_releases}}');

        return true;
    }

    private function createTables(): void
    {
        // Releases
        $this->createTable('{{%peek_releases}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'name' => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'status' => $this->string(20)->notNull()->defaultValue('draft'),
            'scheduledDate' => $this->dateTime()->null(),
            'publishedDate' => $this->dateTime()->null(),
            'publishedBy' => $this->integer()->null(),
            'createdBy' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Release Entries
        $this->createTable('{{%peek_release_entries}}', [
            'id' => $this->primaryKey(),
            'releaseId' => $this->integer()->notNull(),
            'canonicalId' => $this->integer()->notNull(),
            'draftId' => $this->integer()->null(),
            'sortOrder' => $this->smallInteger()->defaultValue(0),
            'status' => $this->string(20)->defaultValue('pending'),
            'errorMessage' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // Releases
        $this->createIndex(null, '{{%peek_releases}}', ['status']);
        $this->createIndex(null, '{{%peek_releases}}', ['scheduledDate']);
        $this->createIndex(null, '{{%peek_releases}}', ['siteId']);

        // Release Entries
        $this->createIndex(null, '{{%peek_release_entries}}', ['releaseId']);
        $this->createIndex(null, '{{%peek_release_entries}}', ['releaseId', 'draftId'], true);
    }

    private function addForeignKeys(): void
    {
        // Releases → sites
        $this->addForeignKey(null, '{{%peek_releases}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', null);
        // Releases → users (publishedBy)
        $this->addForeignKey(null, '{{%peek_releases}}', ['publishedBy'], '{{%users}}', ['id'], 'SET NULL', null);
        // Releases → users (createdBy)
        $this->addForeignKey(null, '{{%peek_releases}}', ['createdBy'], '{{%users}}', ['id'], 'SET NULL', null);

        // Release Entries → releases
        $this->addForeignKey(null, '{{%peek_release_entries}}', ['releaseId'], '{{%peek_releases}}', ['id'], 'CASCADE', null);
        // Release Entries → elements (canonicalId)
        $this->addForeignKey(null, '{{%peek_release_entries}}', ['canonicalId'], '{{%elements}}', ['id'], 'CASCADE', null);
        // Release Entries → elements (draftId) — SET NULL so entry survives draft application
        $this->addForeignKey(null, '{{%peek_release_entries}}', ['draftId'], '{{%elements}}', ['id'], 'SET NULL', null);
    }
}
