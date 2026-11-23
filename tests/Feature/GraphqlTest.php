<?php

declare(strict_types=1);

use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Gql;
use CraftCms\Cms\Support\Facades\Sites;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Services\Releases;

function gql(array $scope, string $query, bool $public = false): array
{
    $gql = app(Gql::class);
    $gql->invalidateCaches();

    return $gql->executeQuery(new GqlSchema(['name' => 'Test', 'scope' => $scope, 'isPublic' => $public]), $query);
}

function fullScope(string $sectionUid, array $peek = ['peekDrafts:read', 'peekReleases:read']): array
{
    return [
        ...$peek,
        'elements.drafts:read',
        'sections.'.$sectionUid.':read',
        'sites.'.Sites::getPrimarySite()->uid.':read',
    ];
}

it('lists drafts and diffs them for a schema that may read them', function () {
    $draft = $this->createDraft($this->createEntry('GQL', ['body' => 'before']), [], ['body' => 'after']);

    $result = gql(fullScope($this->section()->uid), "{ peekDrafts { id title stale } peekDraftDiff(draftId: {$draft->id}) { changedCount fields { handle oldValue newValue } } }");

    expect($result['errors'] ?? null)->toBeNull()
        ->and($result['data']['peekDrafts'][0]['id'])->toBe($draft->id)
        ->and($result['data']['peekDraftDiff']['changedCount'])->toBe(1)
        ->and($result['data']['peekDraftDiff']['fields'][0])->toBe(['handle' => 'body', 'oldValue' => 'before', 'newValue' => 'after']);
});

it('returns nothing on the public schema, whatever its scopes', function () {
    $draft = $this->createDraft($this->createEntry('Public'), ['title' => 'Public draft']);

    $result = gql(fullScope($this->section()->uid), "{ peekDrafts { id } peekDraftDiff(draftId: {$draft->id}) { changedCount } peekReleases { id } }", public: true);

    expect($result['data'])->toBe(['peekDrafts' => [], 'peekDraftDiff' => null, 'peekReleases' => []]);
});

it('needs core’s draft option as well as Peek’s scope', function () {
    $draft = $this->createDraft($this->createEntry('No drafts'), ['title' => 'Draft']);
    $scope = array_values(array_diff(fullScope($this->section()->uid), ['elements.drafts:read']));

    $result = gql($scope, "{ peekDrafts { id } peekDraftDiff(draftId: {$draft->id}) { changedCount } }");

    expect($result['data'])->toBe(['peekDrafts' => [], 'peekDraftDiff' => null]);
});

it('hides drafts in sections the schema can’t read', function () {
    $draft = $this->createDraft($this->createEntry('Other section'), ['title' => 'Draft']);
    $scope = ['peekDrafts:read', 'elements.drafts:read', 'sites.'.Sites::getPrimarySite()->uid.':read'];

    $result = gql($scope, "{ peekDrafts { id } peekDraftDiff(draftId: {$draft->id}) { changedCount } }");

    expect($result['data'])->toBe(['peekDrafts' => [], 'peekDraftDiff' => null]);
});

it('leaves the queries out of a schema without Peek’s scopes', function () {
    $result = gql(['elements.drafts:read'], '{ peekDrafts { id } }');

    // Not in the schema at all (this Craft beta drops unknown fields rather than erroring).
    expect($result['data'] ?? [])->not->toHaveKey('peekDrafts');
});

it('lists releases and their readable entries', function () {
    $release = Release::query()->create(['name' => 'GQL release', 'siteId' => Sites::getPrimarySite()->id]);
    $draft = $this->createDraft($this->createEntry('In release'), ['title' => 'Draft']);
    app(Releases::class)->addDraft($release, $draft, null);

    $result = gql(fullScope($this->section()->uid), "{ peekReleases { id name source entryCount } peekRelease(id: {$release->id}) { name entries { draftId status } } }");

    expect($result['data']['peekReleases'][0])->toBe(['id' => $release->id, 'name' => 'GQL release', 'source' => 'peek', 'entryCount' => 1])
        ->and($result['data']['peekRelease']['entries'])->toBe([['draftId' => $draft->id, 'status' => 'pending']]);

    $noSection = gql(['peekReleases:read', 'sites.'.Sites::getPrimarySite()->uid.':read'], "{ peekRelease(id: {$release->id}) { entries { draftId } } }");
    expect($noSection['data']['peekRelease']['entries'])->toBe([]);
});
