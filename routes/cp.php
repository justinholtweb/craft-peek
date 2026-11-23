<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Peek\Http\Controllers\DashboardController;
use JustinHoltWeb\Peek\Http\Controllers\DiffController;
use JustinHoltWeb\Peek\Http\Controllers\DraftReleasesController;
use JustinHoltWeb\Peek\Http\Controllers\ReleasesController;
use JustinHoltWeb\Peek\Plugin;

// The CP group already applies `auth`, `can:accessCp` and the cpTrigger prefix. Craft only hides
// the nav item for users without access; the routes check it themselves.

Route::middleware('can:'.Plugin::PERMISSION_ACCESS)->group(function () {
    Route::get('peek', DashboardController::class)->name('peek');

    Route::middleware('can:'.Plugin::PERMISSION_VIEW_DIFFS)->group(function () {
        Route::get('peek/diff/{draftId}', [DiffController::class, 'show'])->whereNumber('draftId')->name('peek.diff');
        Route::get('peek/diff/{draftId}/preview', [DiffController::class, 'preview'])->whereNumber('draftId')->name('peek.diff.preview');
    });

    // Either release system's permission: the controller works out which one the site uses.
    Route::get('peek/drafts/{draftId}/releases', [DraftReleasesController::class, 'show'])->whereNumber('draftId')->name('peek.drafts.releases');
    Route::post('peek/drafts/{draftId}/releases', [DraftReleasesController::class, 'store'])->whereNumber('draftId');

    Route::middleware('can:'.Plugin::PERMISSION_MANAGE_RELEASES)->group(function () {
        Route::get('peek/releases', [ReleasesController::class, 'index'])->name('peek.releases');
        Route::get('peek/releases/new', [ReleasesController::class, 'create'])->name('peek.releases.create');
        Route::post('peek/releases', [ReleasesController::class, 'save'])->name('peek.releases.save');
        Route::get('peek/releases/{releaseId}', [ReleasesController::class, 'edit'])->whereNumber('releaseId')->name('peek.releases.edit');
        Route::delete('peek/releases/{releaseId}', [ReleasesController::class, 'destroy'])->whereNumber('releaseId');
        Route::post('peek/releases/{releaseId}/publish', [ReleasesController::class, 'publish'])->whereNumber('releaseId');
        Route::delete('peek/releases/{releaseId}/drafts/{draftId}', [ReleasesController::class, 'removeDraft'])->whereNumber(['releaseId', 'draftId']);
    });
});
