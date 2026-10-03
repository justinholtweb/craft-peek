<?php
/**
 * Peek's controllers and screens, checked over HTTP in the shared plugin-testing harness.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-peek/tests/harness/security.php
 *
 * The Codeception suite covers the services; this covers what only a real request shows: the
 * permission checks in the controllers, the release screen's single form, and the posted shapes
 * Craft's own fields produce. It signs in as an editor with every Peek permission and no rights on
 * the section being released — before 5.0.4, enough to read and publish any draft on the site —
 * and pairs each refusal with the same request made by someone allowed.
 *
 * Self-cleaning: the entry, its drafts, releases and the editor are removed at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\Plugin;
use justinholtweb\peek\records\ReleaseEntryRecord;
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

Craft::$app->getPlugins()->loadPlugins();

$peek = Plugin::getInstance();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'pk-' . bin2hex(random_bytes(12));
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

// An entry with a saved draft, owned by the admin, in a section the editor has no rights to.
$entry = new Entry([
    'sectionId' => $section->id,
    'typeId' => $section->getEntryTypes()[0]->id,
    'authorId' => $admin->id,
    'title' => "Peek live $run",
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

$secret = $makeDraft("Peek secret draft $run");
$other = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'authorId' => $admin->id, 'title' => "Peek other $run"]);
Craft::$app->getElements()->saveElement($other, false);
$cleanup['elements'][] = $other;

$newRelease = function(string $name) use ($peek, $admin, &$cleanup): Release {
    $release = new Release();
    $release->siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $release->name = $name;
    $release->createdBy = $admin->id;
    $peek->releases->saveRelease($release);
    $cleanup['releases'][] = $release->id;

    return $release;
};

$editor = new User();
$editor->username = "peek-editor-$run";
$editor->email = "peek-editor-$run@example.com";
$editor->newPassword = $password;
Craft::$app->getElements()->saveElement($editor, false);
Craft::$app->getUsers()->activateUser($editor);
$cleanup['elements'][] = $editor;
Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, [
    'accesscp', 'accessplugin-peek', 'peek:accessplugin', 'peek:viewdiffs', 'peek:managereleases',
    'peek:publishreleases', 'peek:deletereleases',
]);

function signIn(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $csrf = static function() use ($http): string {
        $info = json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true);

        return (string)($info['csrfTokenValue'] ?? '');
    };
    $login = $http->post('index.php?p=actions/users/login', [
        'headers' => ['Accept' => 'application/json'],
        'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()],
    ]);

    if ($login->getStatusCode() !== 200) {
        echo "Could not sign in as $username: {$login->getStatusCode()}\n";
        exit(1);
    }

    $post = static fn(string $action, array $params, bool $json = false) => $http->post('index.php?p=admin/actions/' . $action, [
        'headers' => $json ? ['Accept' => 'application/json'] : [],
        'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()],
    ]);

    return [$http, $post];
}

[$editorHttp, $editorPost] = signIn($editor->username, $password);
[$adminHttp, $adminPost] = signIn('admin', 'claudepassword');

$isApplied = fn(Entry $draft) => !Entry::find()->id($draft->id)->drafts(true)->status(null)->exists();

// -------------------------------------------------------------------------------------------
echo "\nDiffs\n";

check('a draft in a section the editor can’t see is refused', function() use ($editorHttp, $secret) {
    $view = $editorHttp->get("index.php?p=admin/peek/diff/{$secret->id}")->getStatusCode();
    $preview = $editorHttp->get("index.php?p=admin/peek/diff/{$secret->id}/preview")->getStatusCode();

    return $view === 403 && $preview === 403 ?: "view $view, preview $preview";
});

check('…and shown to someone who can', function() use ($adminHttp, $secret) {
    $response = $adminHttp->get("index.php?p=admin/peek/diff/{$secret->id}");

    return $response->getStatusCode() === 200 && str_contains((string)$response->getBody(), 'Peek secret draft') ?: 'status ' . $response->getStatusCode();
});

check('the dashboard doesn’t list the draft to the editor', function() use ($editorHttp, $run) {
    $html = (string)$editorHttp->get('index.php?p=admin/peek')->getBody();

    return !str_contains($html, "Peek secret draft $run") ?: 'listed';
});

check('…but does to someone who can see it', function() use ($adminHttp, $run) {
    return str_contains((string)$adminHttp->get('index.php?p=admin/peek')->getBody(), "Peek secret draft $run") ?: 'not listed';
});

// -------------------------------------------------------------------------------------------
echo "\nAdding drafts\n";

check('the editor can’t add a draft they couldn’t publish', function() use ($editorPost, $newRelease, $secret, $run) {
    $release = $newRelease("Editor add $run");
    $status = $editorPost('peek/releases/add-entry', ['releaseId' => $release->id, 'draftId' => $secret->id], true)->getStatusCode();
    $rows = ReleaseEntryRecord::find()->where(['releaseId' => $release->id])->count();

    return $status === 403 && (int)$rows === 0 ?: "status $status, rows $rows";
});

check('an admin can, and the entry recorded is the draft’s own — not a posted one', function() use ($adminPost, $newRelease, $secret, $entry, $other, $run) {
    $release = $newRelease("Admin add $run");
    $status = $adminPost('peek/releases/add-entry', ['releaseId' => $release->id, 'draftId' => $secret->id, 'canonicalId' => $other->id], true)->getStatusCode();
    $row = ReleaseEntryRecord::find()->where(['releaseId' => $release->id])->one();

    return $status === 200 && $row !== null && (int)$row->canonicalId === $entry->id ?: "status $status, canonical " . var_export($row?->canonicalId, true);
});

check('the release screen offers the admin the draft to add', function() use ($adminHttp, $newRelease, $run, $secret) {
    $release = $newRelease("Picker $run");
    $html = (string)$adminHttp->get("index.php?p=admin/peek/releases/{$release->id}")->getBody();

    return str_contains($html, 'name="addDraftId"') && str_contains($html, "value=\"{$secret->id}\"") ?: 'no picker';
});

check('…and doesn’t offer it to the editor', function() use ($editorHttp, $newRelease, $run, $secret) {
    $release = $newRelease("No picker $run");
    $html = (string)$editorHttp->get("index.php?p=admin/peek/releases/{$release->id}")->getBody();

    return !str_contains($html, "value=\"{$secret->id}\"") ?: 'offered';
});

// -------------------------------------------------------------------------------------------
echo "\nPublishing\n";

$releaseWith = function(string $name, Entry $draft) use ($newRelease, $peek): Release {
    $release = $newRelease($name);
    $peek->releases->addEntryToRelease($release->id, $draft->getCanonicalId(), $draft->id);

    return $release;
};

check('the editor’s publish applies nothing they couldn’t publish themselves', function() use ($editorPost, $releaseWith, $makeDraft, $isApplied, $peek, $run) {
    $draft = $makeDraft("Editor publish $run");
    $release = $releaseWith("Editor publish $run", $draft);
    $editorPost('peek/releases/publish', ['releaseId' => $release->id]);

    return !$isApplied($draft) && $peek->releases->getReleaseById($release->id)->status === ReleaseStatus::Draft ?: 'applied';
});

check('an admin’s publish applies it', function() use ($adminPost, $releaseWith, $makeDraft, $isApplied, $run) {
    $draft = $makeDraft("Admin publish $run");
    $release = $releaseWith("Admin publish $run", $draft);
    $adminPost('peek/releases/publish', ['releaseId' => $release->id]);

    return $isApplied($draft) ?: 'not applied';
});

check('scheduling needs the schedule permission', function() use ($editorPost, $newRelease, $peek, $run) {
    $release = $newRelease("Editor schedule $run");
    $status = $editorPost('peek/releases/save', [
        'releaseId' => $release->id, 'name' => $release->name, 'status' => 'scheduled',
        'scheduledDate' => ['date' => '1/1/2030', 'time' => '9:00 AM', 'timezone' => 'UTC'],
    ])->getStatusCode();

    return $status === 403 && $peek->releases->getReleaseById($release->id)->status === ReleaseStatus::Draft ?: "status $status";
});

check('a status only the publish sets can’t be posted', function() use ($adminPost, $newRelease, $peek, $run) {
    $release = $newRelease("Posted status $run");
    $status = $adminPost('peek/releases/save', ['releaseId' => $release->id, 'name' => $release->name, 'status' => 'published'])->getStatusCode();

    return $status === 400 && $peek->releases->getReleaseById($release->id)->status === ReleaseStatus::Draft ?: "status $status";
});

check('Craft’s date-time field schedules a release (it used to be a 500)', function() use ($adminPost, $newRelease, $peek, $admin, $run, $secret) {
    $release = $newRelease("Dated $run");
    $peek->releases->addEntryToRelease($release->id, $secret->getCanonicalId(), $secret->id);
    $response = $adminPost('peek/releases/save', [
        'releaseId' => $release->id, 'name' => $release->name, 'status' => 'scheduled',
        'scheduledDate' => ['date' => '1/1/2030', 'time' => '9:00 AM', 'timezone' => 'UTC'],
    ]);
    $saved = $peek->releases->getReleaseById($release->id);

    return $response->getStatusCode() === 302 && $saved->status === ReleaseStatus::Scheduled
        && $saved->scheduledDate?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') === '2030-01-01 09:00' && $saved->scheduledBy === $admin->id
        ?: 'status ' . $response->getStatusCode() . ', ' . $saved->status->value . ', ' . $saved->scheduledDate?->format(DATE_ATOM);
});

// -------------------------------------------------------------------------------------------
echo "\nThe release screen\n";

check('the release screen has one form with one action', function() use ($adminHttp, $newRelease, $run) {
    $release = $newRelease("One form $run");
    $html = (string)$adminHttp->get("index.php?p=admin/peek/releases/{$release->id}")->getBody();
    preg_match('/<main\b.*<\/main>/s', $html, $main);
    $actions = preg_match_all('/name="action"/', $main[0] ?? $html);
    // Craft's CP layout adds its own empty `<form id="x">`; only Peek's count.
    $forms = preg_match_all('/<form\b(?![^>]*id="x")/', $main[0] ?? $html);

    return $actions === 1 && $forms === 1 ?: "$forms forms, $actions action inputs";
});

check('saving the form as rendered saves the release instead of deleting it', function() use ($adminHttp, $newRelease, $peek, $run) {
    $release = $newRelease("Round trip $run");
    $html = (string)$adminHttp->get("index.php?p=admin/peek/releases/{$release->id}")->getBody();
    preg_match('/<main\b.*<\/main>/s', $html, $main);

    // Every hidden input the browser would send, in order — PHP keeps the last of a repeated name.
    $fields = [];
    preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $main[0] ?? '', $inputs, PREG_SET_ORDER);
    foreach ($inputs as [, $name, $value]) {
        $fields[$name] = html_entity_decode($value);
    }
    $fields['name'] = "Round trip renamed $run";
    $action = $fields['action'] ?? '';
    unset($fields['action'], $fields['CRAFT_CSRF_TOKEN']);

    $info = json_decode((string)$adminHttp->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true);
    $adminHttp->post('index.php?p=admin/actions/' . $action, ['form_params' => $fields + ['CRAFT_CSRF_TOKEN' => $info['csrfTokenValue']]]);
    $saved = $peek->releases->getReleaseById($release->id);

    return $saved !== null && $saved->name === "Round trip renamed $run" ?: "action $action, release " . ($saved === null ? 'DELETED' : $saved->name);
});

check('every Peek screen renders for an admin', function() use ($adminHttp, $newRelease, $run, $secret) {
    $release = $newRelease("Screens $run");
    $bad = [];
    foreach (['peek', 'peek/releases', 'peek/releases/new', "peek/releases/{$release->id}", "peek/diff/{$secret->id}", "peek/diff/{$secret->id}/preview", 'peek/settings', ltrim(parse_url($secret->getCpEditUrl(), PHP_URL_PATH) . '?' . parse_url($secret->getCpEditUrl(), PHP_URL_QUERY), '/')] as $path) {
        $url = str_starts_with($path, 'admin/') ? 'index.php?p=' . str_replace('?', '&', $path) : "index.php?p=admin/$path";
        $status = $adminHttp->get($url)->getStatusCode();
        if ($status !== 200) {
            $bad[] = "$path $status";
        }
    }

    return $bad === [] ?: implode(', ', $bad);
});

check('the draft’s editor sidebar offers to add it to a release', function() use ($adminHttp, $newRelease, $run, $secret) {
    $newRelease("Sidebar $run");
    $url = $secret->getCpEditUrl();
    $html = (string)$adminHttp->get('index.php?p=' . ltrim(parse_url($url, PHP_URL_PATH), '/') . '&' . parse_url($url, PHP_URL_QUERY))->getBody();

    return str_contains($html, 'peek-add-' . $secret->id) && str_contains($html, "Sidebar $run") ?: 'no add control';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
