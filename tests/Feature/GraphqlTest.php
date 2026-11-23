<?php

declare(strict_types=1);

use CraftCms\Cms\Field\Data\MultiOptionsFieldData;
use CraftCms\Cms\Field\Data\OptionData;
use CraftCms\Cms\Field\Data\SingleOptionFieldData;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Gql;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Contracts\Support\Arrayable;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Services\Diff;
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

it('counts only the entries a schema can read', function () {
    $release = Release::query()->create(['name' => 'Counted', 'siteId' => Sites::getPrimarySite()->id]);
    app(Releases::class)->addDraft($release, $this->createDraft($this->createEntry('Counted entry'), ['title' => 'Draft']), null);

    $result = gql(['peekReleases:read', 'sites.'.Sites::getPrimarySite()->uid.':read'], '{ peekReleases { entryCount } }');

    expect($result['data']['peekReleases'])->toBe([['entryCount' => 0]]);
});

it('hides Peek releases on sites the schema can’t read', function () {
    Release::query()->create(['name' => 'Other site', 'siteId' => Sites::getPrimarySite()->id]);

    $result = gql(['peekReleases:read'], '{ peekReleases { id } }');

    expect($result['data']['peekReleases'])->toBe([]);
});

it('shows related elements in a diff only as a count', function () {
    $secret = $this->createEntry('Top secret target');
    $canonical = $this->createEntry('Relates');
    $draft = $this->createDraft($canonical, [], [
        'related' => [$secret->id],
        'blocks' => ['new1' => ['type' => 'textGrid', 'enabled' => true, 'fields' => ['heading' => 'H', 'related' => [$secret->id]]]],
    ]);

    $result = gql(fullScope($this->section()->uid), "{ peekDraftDiff(draftId: {$draft->id}) { fields { handle changed oldValue newValue } } }");
    $fields = collect($result['data']['peekDraftDiff']['fields'])->keyBy('handle');
    $json = json_encode($result);

    expect($fields['related'])->toMatchArray(['changed' => true, 'oldValue' => '(none)', 'newValue' => '1 related element'])
        ->and($fields['blocks']['newValue'])->toContain('Related: 1 related element')
        ->and($json)->not->toContain('Top secret target')
        ->and($json)->not->toContain('#'.$secret->id);

    // The CP still names them.
    $cp = collect(app(Diff::class)->diffEntry($draft, $canonical))->keyBy('handle');
    expect($cp['related']->newValue)->toContain('Top secret target');
});

it('redacts anything holding elements, and objects it can’t see into', function () {
    $diff = app(Diff::class);
    $field = new PlainText(['handle' => 'x', 'name' => 'X']);
    $secret = $this->createEntry('Hidden thing');
    $redact = function (mixed $value) use ($diff, $field): string {
        $set = fn (string $prop, bool $on) => (fn () => $this->$prop = $on)->call($diff);
        $set('redactRelations', true);
        $set('displaying', true);

        try {
            return $diff->valueToString($field, $value);
        } finally {
            $set('redactRelations', false);
            $set('displaying', false);
        }
    };

    expect($redact([['nested' => [$secret]]]))->toBe('1 related element')
        ->and($redact(new class implements Arrayable
        {
            public function toArray(): array
            {
                return ['title' => 'Hidden thing'];
            }
        }))->toBe('(not shown over GraphQL)')
        ->and($redact([['col1' => 'plain']]))->toContain('plain')
        ->and($redact([['when' => new DateTimeImmutable('2030-01-02 03:04:05')]]))->toContain('2030-01-02 03:04:05')
        ->and($redact([['x' => new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return 'LEAKED';
            }
        }]]))->toBe('(not shown over GraphQL)')
        ->and($redact('just text'))->toBe('just text')
        ->and($redact(new SingleOptionFieldData('Blue', 'blue', true)))->toBe('blue')
        ->and($redact(new MultiOptionsFieldData([new OptionData('A', 'a', true), new OptionData('B', 'b', true)])))->toBe("a\nb")
        ->and($redact(new class implements Stringable
        {
            public function getRawContent(): string
            {
                return '<p>Hi <a href="{entry:1@1:url}">x</a></p>';
            }

            public function __toString(): string
            {
                return 'RENDERED SECRET';
            }
        }))->toBe('Hi x')
        ->and($redact(new class implements Stringable
        {
            public function __toString(): string
            {
                return 'opaque';
            }
        }))->toBe('(not shown over GraphQL)');
});
