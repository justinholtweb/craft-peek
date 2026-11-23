<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JustinHoltWeb\Peek\Database\Tables;

/**
 * Peek's two tables, as Peek 5.1.2 left them.
 *
 * A site upgrading from Craft 5 already has both (`$minVersionRequired` guarantees the 5.x
 * migrations ran), so each table is skipped when it exists and its rows carry over untouched.
 *
 * `draftId` is nullable with ON DELETE SET NULL, never CASCADE: applying a draft deletes the
 * draft element, and the release's row for it has to outlive that to record that it was published.
 */
class Install extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(Tables::RELEASES)) {
            Schema::create(Tables::RELEASES, function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('siteId');
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('status', 20)->default('draft');
                $table->dateTime('scheduledDate')->nullable();
                $table->dateTime('publishedDate')->nullable();
                $table->integer('publishedBy')->nullable();
                $table->integer('createdBy')->nullable();
                $table->integer('scheduledBy')->nullable();
                $table->dateTime('dateCreated');
                $table->dateTime('dateUpdated');
                $table->char('uid', 36)->default('0');

                $table->index('status');
                $table->index('scheduledDate');
                $table->index('siteId');

                $table->foreign('siteId')->references('id')->on(Table::SITES)->cascadeOnDelete();
                $table->foreign('publishedBy')->references('id')->on(Table::USERS)->nullOnDelete();
                $table->foreign('createdBy')->references('id')->on(Table::USERS)->nullOnDelete();
                $table->foreign('scheduledBy')->references('id')->on(Table::USERS)->nullOnDelete();
            });
        }

        if (! Schema::hasTable(Tables::RELEASE_ENTRIES)) {
            Schema::create(Tables::RELEASE_ENTRIES, function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('releaseId');
                $table->integer('canonicalId');
                $table->integer('draftId')->nullable();
                $table->smallInteger('sortOrder')->default(0);
                $table->string('status', 20)->default('pending');
                $table->text('errorMessage')->nullable();
                $table->dateTime('dateCreated');
                $table->dateTime('dateUpdated');
                $table->char('uid', 36)->default('0');

                $table->index('releaseId');
                $table->unique(['releaseId', 'draftId']);

                $table->foreign('releaseId')->references('id')->on(Tables::RELEASES)->cascadeOnDelete();
                $table->foreign('canonicalId')->references('id')->on(Table::ELEMENTS)->cascadeOnDelete();
                $table->foreign('draftId')->references('id')->on(Table::ELEMENTS)->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::RELEASE_ENTRIES);
        Schema::dropIfExists(Tables::RELEASES);
    }
}
