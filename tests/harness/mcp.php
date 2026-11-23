<?php
/**
 * Peek's MCP tools through Craft 5.12.1's real stdio server (`php craft mcp/serve`), in the shared
 * plugin-testing harness.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-peek/tests/harness/mcp.php
 *
 * The Codeception suite calls the tool classes directly on Craft 5.10; this proves what only the
 * real server shows: registration on 5.12.1, the tool names and input schemas, the permission and
 * scope gates (`RequiresPermission`, readOnlyHint), argument validation and error results.
 *
 * Self-cleaning: the entry, its drafts, releases and the editor are removed at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
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

Craft::$app->getPlugins()->loadPlugins();

if (!Plugin::mcpAvailable()) {
    echo 'Peek’s MCP tools need Craft ' . Plugin::MCP_MIN_CRAFT_VERSION . '+ (this is ' . Craft::$app->getVersion() . ").\n";
    exit(0);
}

/**
 * Runs JSON-RPC requests through `php craft mcp/serve` as a user; returns each response by id.
 *
 * @param array<int, array{0: string, 1: array<string, mixed>}> $requests id => [method, params]
 * @return array<int, array<string, mixed>>
 */
function mcp(string $user, array $requests): array
{
    $lines = [
        json_encode(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'peek-harness', 'version' => '1']]]),
        json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
    ];

    foreach ($requests as $id => [$method, $params]) {
        $lines[] = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object)$params]);
    }

    $proc = proc_open(['php', 'craft', 'mcp/serve', '--user=' . $user], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, getcwd());
    foreach ($lines as $line) {
        fwrite($pipes[0], $line . "\n");
    }

    stream_set_blocking($pipes[1], false);
    $buffer = '';
    $out = [];
    $deadline = microtime(true) + 90;

    while (microtime(true) < $deadline && count($out) < count($requests) + 1) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === '' || $chunk === false) {
            usleep(50000);
            continue;
        }
        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $msg = json_decode(substr($buffer, 0, $pos), true);
            $buffer = substr($buffer, $pos + 1);
            if (isset($msg['id'])) {
                $out[$msg['id']] = $msg;
            }
        }
    }

    fclose($pipes[0]);
    proc_terminate($proc);

    return $out;
}

/**
 * The tools a user sees, by name, following tools/list pagination.
 *
 * @return array<string, array<string, mixed>>
 */
function tools(string $user): array
{
    $tools = [];
    $cursor = null;

    for ($i = 1; $i <= 10; $i++) {
        $response = mcp($user, [1 => ['tools/list', $cursor ? ['cursor' => $cursor] : []]])[1] ?? [];
        foreach ($response['result']['tools'] ?? [] as $tool) {
            $tools[$tool['name']] = $tool;
        }
        $cursor = $response['result']['nextCursor'] ?? null;
        if (!$cursor) {
            break;
        }
    }

    return $tools;
}

/**
 * @return string[]
 */
function toolNames(string $user): array
{
    return array_keys(tools($user));
}

/** The structured result of a tool call, or the error text. */
function result(array $response): array|string
{
    if (isset($response['error'])) {
        return 'JSON-RPC error ' . $response['error']['code'] . ': ' . $response['error']['message'];
    }
    if (!empty($response['result']['isError'])) {
        return 'Tool error: ' . ($response['result']['content'][0]['text'] ?? '');
    }

    return $response['result']['structuredContent'] ?? [];
}

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
    'title' => "Peek MCP $run",
    'slug' => "peek-mcp-$run",
    'postDate' => new DateTime(),
]);
Craft::$app->getElements()->saveElement($entry, false);
$cleanup['elements'][] = $entry;

$draft = Craft::$app->getDrafts()->createDraft($entry, $admin->id, "MCP draft $run");
$draft->title = "Peek MCP secret $run";
Craft::$app->getElements()->saveElement($draft, false);
$cleanup['elements'][] = $draft;

$release = new Release();
$release->siteId = Craft::$app->getSites()->getPrimarySite()->id;
$release->name = "Peek MCP release $run";
$release->createdBy = $admin->id;
$peek->releases->saveRelease($release);
$cleanup['releases'][] = $release->id;

// Every Peek permission and none on the news section.
$editor = new User(['username' => "peek-mcp-$run", 'email' => "peek-mcp-$run@example.com"]);
Craft::$app->getElements()->saveElement($editor, false);
Craft::$app->getUsers()->activateUser($editor);
$cleanup['elements'][] = $editor;
Craft::$app->getUserPermissions()->saveUserPermissions($editor->id, [
    'accesscp', 'peek:accessplugin', 'peek:viewdiffs', 'peek:managereleases', 'peek:publishreleases',
]);

// Only Peek's dashboard.
$viewer = new User(['username' => "peek-mcp-viewer-$run", 'email' => "peek-mcp-viewer-$run@example.com"]);
Craft::$app->getElements()->saveElement($viewer, false);
Craft::$app->getUsers()->activateUser($viewer);
$cleanup['elements'][] = $viewer;
Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, ['accesscp', 'peek:accessplugin']);

$all = ['peek_drafts_list', 'peek_draft_diff', 'peek_releases_list', 'peek_release_get', 'peek_release_add_draft', 'peek_release_publish'];

echo 'Craft ' . Craft::$app->getVersion() . "\n";

echo "\nDiscovery\n";

check('an admin sees all six Peek tools', function() use ($all) {
    $names = toolNames('admin');
    $missing = array_diff($all, $names);

    return $missing === [] ? true : 'Missing: ' . implode(', ', $missing);
});

check('tools are gated by Peek’s permissions', function() use ($viewer) {
    $peekTools = array_values(array_filter(toolNames((string)$viewer->id), fn($n) => str_starts_with($n, 'peek_')));

    return $peekTools === ['peek_drafts_list'] ? true : $peekTools;
});

check('read-only tools are marked readOnlyHint, the write tools aren’t', function() {
    $tools = tools('admin');
    foreach (['peek_drafts_list', 'peek_draft_diff', 'peek_releases_list', 'peek_release_get'] as $name) {
        if (($tools[$name]['annotations']['readOnlyHint'] ?? null) !== true) {
            return "$name isn’t read-only";
        }
    }
    foreach (['peek_release_add_draft', 'peek_release_publish'] as $name) {
        if (!isset($tools[$name]) || ($tools[$name]['annotations']['readOnlyHint'] ?? false) === true) {
            return "$name is marked read-only (or missing)";
        }
    }

    return true;
});

echo "\nAs an admin\n";

$responses = mcp('admin', [
    1 => ['tools/call', ['name' => 'peek_drafts_list', 'arguments' => ['limit' => 100]]],
    2 => ['tools/call', ['name' => 'peek_draft_diff', 'arguments' => ['draftId' => $draft->id]]],
    3 => ['tools/call', ['name' => 'peek_release_add_draft', 'arguments' => ['releaseId' => $release->id, 'draftId' => $draft->id]]],
    4 => ['tools/call', ['name' => 'peek_release_get', 'arguments' => ['releaseId' => $release->id]]],
    5 => ['tools/call', ['name' => 'peek_releases_list', 'arguments' => ['status' => 'draft']]],
    6 => ['tools/call', ['name' => 'peek_drafts_list', 'arguments' => ['limit' => 500]]],
    7 => ['tools/call', ['name' => 'peek_release_get', 'arguments' => ['releaseId' => 99999999]]],
]);

check('peek_drafts_list lists the draft', function() use ($responses, $draft) {
    $result = result($responses[1] ?? []);
    if (is_string($result)) {
        return $result;
    }
    $ids = array_column($result['drafts'], 'id');

    return in_array($draft->id, $ids, true) ? true : 'Draft not in ' . json_encode($ids);
});

check('peek_draft_diff returns the changed title as plain text', function() use ($responses, $run) {
    $result = result($responses[2] ?? []);
    if (is_string($result)) {
        return $result;
    }
    $title = array_values(array_filter($result['fields'], fn($f) => $f['handle'] === 'title'))[0] ?? null;

    return $title && $title['changed'] && $title['new'] === "Peek MCP secret $run" ? true : json_encode($result);
});

check('peek_release_add_draft adds it', function() use ($responses) {
    $result = result($responses[3] ?? []);

    return is_array($result) && $result['entryCount'] === 1 ? true : $result;
});

check('peek_release_get shows the entry with its title and no problems', function() use ($responses, $run) {
    $result = result($responses[4] ?? []);

    return is_array($result) && $result['entries'][0]['title'] === "Peek MCP secret $run" && $result['problems'] === [] ? true : $result;
});

check('peek_releases_list filters by status', function() use ($responses, $release) {
    $result = result($responses[5] ?? []);

    return is_array($result) && in_array($release->id, array_column($result['releases'], 'id'), true) ? true : $result;
});

check('the server rejects a limit over 100 before Peek runs', function() use ($responses) {
    $response = $responses[6] ?? [];
    $text = json_encode($response);

    return isset($response['error']) || !empty($response['result']['isError']) ? true : $text;
});

check('an unknown release is a tool error', function() use ($responses) {
    return result($responses[7] ?? []) === 'Tool error: Release not found.' ? true : result($responses[7] ?? []);
});

echo "\nAs an editor with every Peek permission and no rights on the section\n";

$responses = mcp((string)$editor->id, [
    1 => ['tools/call', ['name' => 'peek_drafts_list', 'arguments' => ['limit' => 100]]],
    2 => ['tools/call', ['name' => 'peek_draft_diff', 'arguments' => ['draftId' => $draft->id]]],
    3 => ['tools/call', ['name' => 'peek_release_get', 'arguments' => ['releaseId' => $release->id]]],
    4 => ['tools/call', ['name' => 'peek_release_publish', 'arguments' => ['releaseId' => $release->id]]],
]);

check('the draft isn’t listed', function() use ($responses, $draft) {
    $result = result($responses[1] ?? []);

    return is_array($result) && !in_array($draft->id, array_column($result['drafts'], 'id'), true) ? true : $result;
});

check('its diff is “not found”', function() use ($responses) {
    return result($responses[2] ?? []) === 'Tool error: Draft not found.' ? true : result($responses[2] ?? []);
});

check('the release shows the entry without its title', function() use ($responses, $run) {
    $result = result($responses[3] ?? []);

    return is_array($result) && $result['entries'][0]['title'] === null && !str_contains(json_encode($result), "secret $run") ? true : $result;
});

check('publishing as the editor is refused and applies nothing', function() use ($responses, $draft, $run) {
    $result = result($responses[4] ?? []);
    $stillThere = Entry::find()->id($draft->id)->drafts(true)->status(null)->exists();

    return is_array($result) && $result['published'] === false && count($result['problems']) === 1
        && !str_contains(json_encode($result), "secret $run") && $stillThere ? true : $result;
});

echo "\nPublishing as the admin\n";

$responses = mcp('admin', [
    1 => ['tools/call', ['name' => 'peek_release_publish', 'arguments' => ['releaseId' => $release->id, 'dryRun' => true]]],
    2 => ['tools/call', ['name' => 'peek_release_publish', 'arguments' => ['releaseId' => $release->id]]],
]);

check('a dry run reports no problems and publishes nothing', function() use ($responses) {
    $result = result($responses[1] ?? []);

    return is_array($result) && $result['dryRun'] === true && $result['published'] === false && $result['problems'] === [] ? true : $result;
});

check('publishing applies the draft, recorded as the admin', function() use ($responses, $draft, $peek, $release, $admin) {
    $result = result($responses[2] ?? []);
    $applied = !Entry::find()->id($draft->id)->drafts(true)->status(null)->exists();
    $saved = $peek->releases->getReleaseById($release->id);

    return is_array($result) && $result['published'] === true && $applied && $saved->publishedBy === $admin->id ? true : $result;
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
