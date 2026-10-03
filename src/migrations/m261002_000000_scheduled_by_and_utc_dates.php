<?php

namespace justinholtweb\peek\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;

/**
 * Adds `scheduledBy`, and moves release dates to UTC.
 *
 * A scheduled publish runs in the queue with nobody signed in, so it needs a user to authorize
 * each draft against — the one who scheduled it. Releases already scheduled get their creator.
 *
 * Release dates were written with `DateTime::format()` in the system time zone, while Craft stores
 * every other date in UTC and the scheduler now compares in UTC. Converting them keeps an existing
 * schedule firing at the moment it was set for.
 */
class m261002_000000_scheduled_by_and_utc_dates extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%peek_releases}}';

        if (!$this->db->columnExists($table, 'scheduledBy')) {
            $this->addColumn($table, 'scheduledBy', $this->integer()->null()->after('createdBy'));
            $this->addForeignKey(null, $table, ['scheduledBy'], '{{%users}}', ['id'], 'SET NULL', null);
        }

        $this->update($table, ['scheduledBy' => new \yii\db\Expression('[[createdBy]]')], ['status' => 'scheduled', 'scheduledBy' => null], [], false);

        $timeZone = new \DateTimeZone(Craft::$app->getTimeZone());

        foreach ((new Query())->select(['id', 'scheduledDate', 'publishedDate'])->from($table)->all($this->db) as $row) {
            $values = [];

            foreach (['scheduledDate', 'publishedDate'] as $column) {
                if ($row[$column] !== null) {
                    $values[$column] = Db::prepareDateForDb(new \DateTime($row[$column], $timeZone));
                }
            }

            if ($values !== []) {
                $this->update($table, $values, ['id' => $row['id']], [], false);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261002_000000_scheduled_by_and_utc_dates cannot be reverted.\n";

        return false;
    }
}
