<?php

namespace justinholtweb\peek\console\controllers;

use Craft;
use craft\console\Controller;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\queue\jobs\PublishReleaseJob;
use justinholtweb\peek\records\ReleaseRecord;
use yii\console\ExitCode;

/**
 * Scheduler for publishing scheduled releases.
 * Run via cron: `php craft peek/scheduler/check` every minute.
 */
class SchedulerController extends Controller
{
    public $defaultAction = 'check';

    /**
     * Check for overdue scheduled releases and push publish jobs to the queue.
     */
    public function actionCheck(): int
    {
        $now = new \DateTime();

        /** @var ReleaseRecord[] $records */
        $records = ReleaseRecord::find()
            ->where(['status' => ReleaseStatus::Scheduled->value])
            ->andWhere(['<=', 'scheduledDate', $now->format('Y-m-d H:i:s')])
            ->all();

        if (empty($records)) {
            $this->stdout("No scheduled releases due.\n");
            return ExitCode::OK;
        }

        foreach ($records as $record) {
            $this->stdout("Queuing release #{$record->id}: {$record->name}\n");

            Craft::$app->getQueue()->push(new PublishReleaseJob([
                'releaseId' => $record->id,
            ]));

            // Mark as publishing so it won't be picked up again
            $record->status = ReleaseStatus::Publishing->value;
            $record->save(false);
        }

        $this->stdout("Queued " . count($records) . " release(s) for publishing.\n");

        return ExitCode::OK;
    }
}
