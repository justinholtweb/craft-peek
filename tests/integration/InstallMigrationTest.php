<?php

namespace justinholtweb\peek\tests\integration;

use Craft;
use craft\db\Query;
use justinholtweb\peek\records\ReleaseEntryRecord;
use justinholtweb\peek\records\ReleaseRecord;
use justinholtweb\peek\tests\Support\PeekTestCase;

/**
 * Pins the schema the install migration produces — the referential rules here
 * are what let a release entry outlive the draft it points at.
 */
class InstallMigrationTest extends PeekTestCase
{
    private function columns(string $table): array
    {
        return Craft::$app->getDb()->getTableSchema($table, true)->columns;
    }

    public function testReleasesTableShape(): void
    {
        $columns = $this->columns(ReleaseRecord::tableName());

        foreach (['id', 'siteId', 'name', 'description', 'status', 'scheduledDate',
            'publishedDate', 'publishedBy', 'createdBy', 'dateCreated', 'dateUpdated', 'uid', ] as $column) {
            $this->assertArrayHasKey($column, $columns, "Missing column: $column");
        }

        $this->assertFalse($columns['siteId']->allowNull);
        $this->assertFalse($columns['name']->allowNull);
        $this->assertSame('draft', $columns['status']->defaultValue);
        $this->assertTrue($columns['publishedBy']->allowNull, 'SET NULL FKs need a nullable column.');
        $this->assertTrue($columns['createdBy']->allowNull, 'SET NULL FKs need a nullable column.');
    }

    public function testReleaseEntriesTableShape(): void
    {
        $columns = $this->columns(ReleaseEntryRecord::tableName());

        foreach (['id', 'releaseId', 'canonicalId', 'draftId', 'sortOrder',
            'status', 'errorMessage', 'dateCreated', 'dateUpdated', 'uid', ] as $column) {
            $this->assertArrayHasKey($column, $columns, "Missing column: $column");
        }

        $this->assertFalse($columns['releaseId']->allowNull);
        $this->assertFalse($columns['canonicalId']->allowNull);
        $this->assertTrue($columns['draftId']->allowNull, 'draftId must be nullable for its SET NULL FK.');
        $this->assertSame('pending', $columns['status']->defaultValue);
    }

    public function testReleaseIsScopedToASite(): void
    {
        $this->assertTrue(
            in_array('siteId', $this->indexedColumns(ReleaseRecord::tableName()), true),
            'Releases should be indexed by site.',
        );
    }

    public function testADraftCannotBeAddedToTheSameReleaseTwiceAtTheDatabaseLevel(): void
    {
        $release = new \justinholtweb\peek\models\Release();
        $release->siteId = $this->primarySiteId();
        $release->name = 'Unique';
        $this->assertTrue($this->releases()->saveRelease($release));

        $canonical = $this->createEntry('Unique canonical');
        $draft = $this->createDraft($canonical, ['title' => 'Unique draft']);

        $this->assertTrue($this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id));

        // Bypass the service's duplicate check — the unique index must still hold.
        $record = new ReleaseEntryRecord();
        $record->releaseId = $release->id;
        $record->canonicalId = $canonical->id;
        $record->draftId = $draft->id;

        $this->expectException(\yii\db\IntegrityException::class);
        $record->save(false);
    }

    public function testDeletingASiteRemovesItsReleases(): void
    {
        $release = new \justinholtweb\peek\models\Release();
        $release->siteId = $this->primarySiteId();
        $release->name = 'Site bound';
        $this->assertTrue($this->releases()->saveRelease($release));

        // The FK is CASCADE, so a release can never outlive its site.
        $this->assertSame(
            'CASCADE',
            $this->deleteRuleFor(ReleaseRecord::tableName(), 'siteId'),
        );
    }

    public function testDraftForeignKeyIsSetNullAndCanonicalIsCascade(): void
    {
        $this->assertSame('SET NULL', $this->deleteRuleFor(ReleaseEntryRecord::tableName(), 'draftId'));
        $this->assertSame('CASCADE', $this->deleteRuleFor(ReleaseEntryRecord::tableName(), 'canonicalId'));
        $this->assertSame('CASCADE', $this->deleteRuleFor(ReleaseEntryRecord::tableName(), 'releaseId'));
    }

    private function indexedColumns(string $table): array
    {
        $name = Craft::$app->getDb()->getSchema()->getRawTableName($table);

        return (new Query())
            ->select('COLUMN_NAME')
            ->from('information_schema.STATISTICS')
            ->where(['TABLE_SCHEMA' => new \yii\db\Expression('DATABASE()'), 'TABLE_NAME' => $name])
            ->column();
    }

    private function deleteRuleFor(string $table, string $column): ?string
    {
        $name = Craft::$app->getDb()->getSchema()->getRawTableName($table);

        return (new Query())
            ->select('rc.DELETE_RULE')
            ->from(['rc' => 'information_schema.REFERENTIAL_CONSTRAINTS'])
            ->innerJoin(
                ['kcu' => 'information_schema.KEY_COLUMN_USAGE'],
                'kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA',
            )
            ->where([
                'rc.CONSTRAINT_SCHEMA' => new \yii\db\Expression('DATABASE()'),
                'kcu.TABLE_NAME' => $name,
                'kcu.COLUMN_NAME' => $column,
            ])
            ->scalar() ?: null;
    }
}
