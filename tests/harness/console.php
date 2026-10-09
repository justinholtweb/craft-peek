<?php
/**
 * Peek's `peek/releases/*` console commands, run for real in the shared plugin-testing harness.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-peek/tests/harness/console.php
 *
 * Sets up an entry with drafts and releases, then runs `php craft peek/releases/list|status|publish`
 * as a child process and checks the output, the exit codes and what was applied — including that
 * `--as` holds a publish to a user's own rights and `--dry-run` applies nothing.
 *
 * Self-cleaning: the entry, its drafts, releases and the editor are removed at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\Plugin;
use justinholtweb\peek\records\ReleaseRecord;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

/**
 * Runs `php craft <args>` non-interactively and returns [exit code, stdout+stderr].
 *
 * @return array{int, string}
 */
function craft(string $args): array
{
    exec(PHP_BINARY . ' craft ' . $args . ' --interactive=0 2>&1', $lines, $code);

    return [$code, implode("\n", $lines)];
}

/** Decodes the JSON a command printed (the whole output). */
function json(string $out): mixed
{
    return json_decode($out, true);
}

Craft::$app->getPlugins()->loadPlugins();

$peek = Plugin::getInstance();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$cleanup = ['elements' => [], 'releases' => []];

register_shutdown_function(function() use (&$cleanup) {
    foreach ($cleanup['releases'] as $id) {
        ReleaseRecord::deleteAll(['id' => $id]);
    }
    foreach (array_reverse($cleanup['elements']) as $element) {
        Craft::$app->getElements()->deleteElement($element, true);
    }
});

$admin = User::find()->admin()->status(null)->one();
$section = Craft::$app->getEntries()->getSectionByHandle('news');

if ($section === null) {
    echo "This check needs the harness's `news` section.\n";
    exit(1);
}

$entry = new Entry([
    'sectionId' => $section->id,
    'typeId' => $section->getEntryTypes()[0]->id,
    'authorId' => $admin->id,
    'title' => "Peek console $run",
]);
Craft::$app->getElements()->saveElement($entry, false);
$cleanup['elements'][] = $entry;

$makeDraft = function(string $title) use ($entry, $admin, &$cleanup): Entry {
    $draft = Craft::$app->getDrafts()->createDraft($entry, $admin->id, $title);
    $draft->title = $title;
    Craft::$app->getElements()->saveElement($draft, false);
    $cleanup['elements'][] = $draft;

    return $draft;
};

$newRelease = function(string $name, array $drafts = []) use ($peek, $admin, &$cleanup): Release {
    $release = new Release();
    $release->siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $release->name = $name;
    $release->createdBy = $admin->id;
    $peek->releases->saveRelease($release);
    $cleanup['releases'][] = $release->id;

    foreach ($drafts as $draft) {
        $peek->releases->addEntryToRelease($release->id, $draft->getCanonicalId(), $draft->id);
    }

    return $peek->releases->getReleaseById($release->id);
};

$isApplied = fn(Entry $draft) => !Entry::find()->id($draft->id)->drafts(true)->status(null)->exists();
$statusOf = fn(Release $r) => $peek->releases->getReleaseById($r->id)->status;

// An editor with every Peek permission and no rights on the `news` section.
$editor = new User();
$editor->username = "peek-cli-editor-$run";
$editor->email = "peek-cli-editor-$run@example.com";
Craft::$app->getElements()->saveElement($editor, false);
Craft::$app->getUsers()->activateUser($editor);
$cleanup['elements'][] = $editor;
Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, [
    'accesscp', 'accessplugin-peek', 'peek:accessplugin', 'peek:managereleases', 'peek:publishreleases',
]);

// -------------------------------------------------------------------------------------------
echo "\nList\n";

$listed = $newRelease("CLI list $run", [$makeDraft("CLI list draft $run")]);

check('list prints the release in a table', function() use ($listed, $run) {
    [$code, $out] = craft('peek/releases/list');

    return $code === 0 && str_contains($out, "CLI list $run") && str_contains($out, (string)$listed->id) ?: "exit $code: $out";
});

check('list --json gives the release with its entry count', function() use ($listed) {
    [$code, $out] = craft('peek/releases/list --json');
    $row = array_values(array_filter(json($out) ?? [], fn($r) => $r['id'] === $listed->id))[0] ?? null;

    return $code === 0 && $row !== null && $row['entryCount'] === 1 && $row['status'] === 'draft' ?: "exit $code: $out";
});

check('list --status filters, and an unknown status is a usage error', function() use ($listed) {
    [, $out] = craft('peek/releases/list --json --status=published');
    $ids = array_column(json($out) ?? [], 'id');
    [$bad] = craft('peek/releases/list --status=nope');

    return !in_array($listed->id, $ids, true) && $bad === 64 ?: 'ids ' . implode(',', $ids) . ", bad exit $bad";
});

check('list --site with an unknown handle is a usage error', function() {
    [$code] = craft('peek/releases/list --site=no-such-site');

    return $code === 64 ?: "exit $code";
});

// -------------------------------------------------------------------------------------------
echo "\nStatus\n";

check('status of a healthy release exits 0 with its entries', function() use ($listed) {
    [$code, $out] = craft("peek/releases/status {$listed->id} --json");
    $data = json($out);

    return $code === 0 && count($data['entries'] ?? []) === 1 && $data['problems'] === [] ?: "exit $code: $out";
});

check('status of an empty release exits 1 and says why', function() use ($newRelease, $run) {
    $empty = $newRelease("CLI empty $run");
    [$code, $out] = craft("peek/releases/status {$empty->id}");

    return $code === 1 && str_contains($out, 'no entries') ?: "exit $code: $out";
});

check('status of a missing release exits 65', function() {
    [$code] = craft('peek/releases/status 999999999');

    return $code === 65 ?: "exit $code";
});

// -------------------------------------------------------------------------------------------
echo "\nPublish\n";

check('--dry-run reports the release publishable and applies nothing', function() use ($newRelease, $makeDraft, $isApplied, $statusOf, $run) {
    $draft = $makeDraft("CLI dry draft $run");
    $release = $newRelease("CLI dry $run", [$draft]);
    [$code, $out] = craft("peek/releases/publish {$release->id} --dry-run");

    return $code === 0 && str_contains($out, 'can be published') && !$isApplied($draft) && $statusOf($release) === ReleaseStatus::Draft ?: "exit $code: $out";
});

check('--as a user who can’t publish a draft refuses, and applies nothing', function() use ($newRelease, $makeDraft, $isApplied, $statusOf, $editor, $run) {
    $draft = $makeDraft("CLI editor draft $run");
    $release = $newRelease("CLI as editor $run", [$draft]);
    [$code, $out] = craft("peek/releases/publish {$release->id} --as={$editor->username} --json");
    $data = json($out);

    return $code === 1 && ($data['published'] ?? null) === false && str_contains(implode(' ', $data['problems'] ?? []), 'isn’t allowed')
        && !$isApplied($draft) && $statusOf($release) === ReleaseStatus::Draft ?: "exit $code: $out";
});

check('--as an unknown user is refused before anything runs', function() use ($listed) {
    [$code] = craft("peek/releases/publish {$listed->id} --as=nobody-$listed->id@example.invalid");

    return $code === 77 ?: "exit $code";
});

check('--as an admin publishes, and records the admin as publisher', function() use ($newRelease, $makeDraft, $isApplied, $peek, $admin, $run) {
    $draft = $makeDraft("CLI admin draft $run");
    $release = $newRelease("CLI as admin $run", [$draft]);
    [$code, $out] = craft("peek/releases/publish {$release->id} --as={$admin->username}");
    $after = $peek->releases->getReleaseById($release->id);

    return $code === 0 && $isApplied($draft) && $after->status === ReleaseStatus::Published && $after->publishedBy === $admin->id ?: "exit $code: $out";
});

check('without --as it publishes as the system: every draft applied, release published', function() use ($newRelease, $makeDraft, $isApplied, $statusOf, $run) {
    $a = $makeDraft("CLI system draft A $run");
    $b = $makeDraft("CLI system draft B $run");
    $release = $newRelease("CLI system $run", [$a, $b]);
    [$code, $out] = craft("peek/releases/publish {$release->id} --json");
    $data = json($out);

    return $code === 0 && ($data['published'] ?? null) === true && $isApplied($a) && $isApplied($b) && $statusOf($release) === ReleaseStatus::Published ?: "exit $code: $out";
});

check('a published release isn’t published again', function() use ($newRelease, $makeDraft, $run) {
    $release = $newRelease("CLI twice $run", [$makeDraft("CLI twice draft $run")]);
    craft("peek/releases/publish {$release->id}");
    [$code, $out] = craft("peek/releases/publish {$release->id}");

    return $code === 1 && str_contains($out, 'already published') ?: "exit $code: $out";
});

check('a release the queue is publishing is left to the queue', function() use ($newRelease, $makeDraft, $isApplied, $peek, $run) {
    $draft = $makeDraft("CLI queued draft $run");
    $release = $newRelease("CLI queued $run", [$draft]);
    $release->status = ReleaseStatus::Publishing;
    $peek->releases->saveRelease($release);
    [$code, $out] = craft("peek/releases/publish {$release->id}");

    return $code === 1 && str_contains($out, 'queue') && !$isApplied($draft) ?: "exit $code: $out";
});

check('an empty release isn’t published', function() use ($newRelease, $statusOf, $run) {
    $empty = $newRelease("CLI empty publish $run");
    [$code, $out] = craft("peek/releases/publish {$empty->id}");

    return $code === 1 && str_contains($out, 'no entries') && $statusOf($empty) === ReleaseStatus::Draft ?: "exit $code: $out";
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
