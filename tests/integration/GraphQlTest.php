<?php

namespace justinholtweb\peek\tests\integration;

use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Entries as EntriesField;
use craft\fields\Link as LinkField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\gql\GqlEntityRegistry;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\GqlSchema;
use justinholtweb\peek\gql\queries\PeekQueries;
use justinholtweb\peek\gql\resolvers\PeekResolver;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\tests\Support\PeekTestCase;

/**
 * Peek's GraphQL queries. Drafts are unpublished content: nothing on the public schema, drafts only
 * with Craft's "Query for element drafts" as well as Peek's scope, and only in sections and sites
 * the schema can read.
 */
class GraphQlTest extends PeekTestCase
{
    protected function _after(): void
    {
        Craft::$app->getGql()->setActiveSchema(null);
        Craft::$app->getGql()->flushCaches();
        GqlEntityRegistry::flush();
        parent::_after();
    }

    /**
     * @param string[] $scope
     */
    private function schema(array $scope, bool $public = false): GqlSchema
    {
        $schema = new GqlSchema(['name' => 'Test ' . uniqid(), 'scope' => $scope, 'isPublic' => $public]);
        // Craft caches query results by schema and query; these schemas are never saved.
        Craft::$app->getGql()->invalidateCaches();
        Craft::$app->getGql()->flushCaches();
        Craft::$app->getGql()->setActiveSchema($schema);

        return $schema;
    }

    /**
     * Everything Peek's queries can use: both scopes, drafts, the test section and the primary site.
     *
     * @param string[] $without
     * @return string[]
     */
    private function fullScope(array $without = []): array
    {
        $scope = [
            'peekDrafts:read',
            'peekReleases:read',
            'elements.drafts:read',
            'sections.' . $this->ensureSection()->uid . ':read',
            'sites.' . Craft::$app->getSites()->getPrimarySite()->uid . ':read',
        ];

        return array_values(array_filter($scope, fn($s) => !in_array($s, $without, true)));
    }

    private function release(): Release
    {
        $release = new Release();
        $release->siteId = $this->primarySiteId();
        $release->name = 'GraphQL release';
        $release->createdBy = $this->adminId();
        $this->assertTrue($this->releases()->saveRelease($release));

        return $release;
    }

    private function draftInRelease(Release $release, string $title = 'Draft'): \craft\elements\Entry
    {
        $canonical = $this->createEntry($title, ['body' => 'before']);
        $draft = $this->createDraft($canonical, ['title' => "$title changed", 'enabled' => false], ['body' => 'after']);
        $this->assertTrue($this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id));

        return $draft;
    }

    public function testQueriesFollowTheSchemaScopes(): void
    {
        $this->schema($this->fullScope());
        $this->assertSame(['peekDrafts', 'peekDraftDiff', 'peekReleases', 'peekRelease'], array_keys(PeekQueries::getQueries()));

        $this->schema($this->fullScope(['peekDrafts:read']));
        $this->assertSame(['peekReleases', 'peekRelease'], array_keys(PeekQueries::getQueries()));

        $this->schema($this->fullScope(['elements.drafts:read']));
        $this->assertSame(['peekReleases', 'peekRelease'], array_keys(PeekQueries::getQueries()), 'Drafts need Craft’s draft option too.');

        $this->schema($this->fullScope(['peekReleases:read']));
        $this->assertSame(['peekDrafts', 'peekDraftDiff'], array_keys(PeekQueries::getQueries()));

        $this->schema($this->fullScope(), public: true);
        $this->assertSame([], PeekQueries::getQueries(), 'Never on the public schema.');
    }

    public function testDraftsAndDiffsWithEveryScope(): void
    {
        $draft = $this->draftInRelease($this->release(), 'Visible');
        $this->schema($this->fullScope());

        $drafts = PeekResolver::drafts(null, []);
        $this->assertCount(1, $drafts);
        $this->assertSame($draft->id, $drafts[0]['id']);
        $this->assertSame('testSection', $drafts[0]['sectionHandle']);
        $this->assertSame('Visible changed', $drafts[0]['title']);

        $diff = PeekResolver::draftDiff(null, ['draftId' => $draft->id]);
        $this->assertSame(3, $diff['changedCount']);
        $fields = array_column($diff['fields'], null, 'handle');
        $this->assertSame('On', $fields['enabled']['oldValue']);
        $this->assertSame('Off', $fields['enabled']['newValue']);
        $this->assertSame('after', $fields['body']['newValue']);
    }

    public function testThePublicSchemaGetsNothing(): void
    {
        $release = $this->release();
        $draft = $this->draftInRelease($release);
        $this->schema($this->fullScope(), public: true);

        $this->assertSame([], PeekResolver::drafts(null, []));
        $this->assertNull(PeekResolver::draftDiff(null, ['draftId' => $draft->id]));
        $this->assertSame([], PeekResolver::releases(null, []));
        $this->assertNull(PeekResolver::releaseById(null, ['id' => $release->id]));
    }

    public function testNoActiveSchemaGetsNothing(): void
    {
        $draft = $this->draftInRelease($this->release());

        $this->assertSame([], PeekResolver::drafts(null, []));
        $this->assertNull(PeekResolver::draftDiff(null, ['draftId' => $draft->id]));
        $this->assertSame([], PeekResolver::releases(null, []));
    }

    public function testDraftsNeedCraftsDraftOption(): void
    {
        $draft = $this->draftInRelease($this->release());
        $this->schema($this->fullScope(['elements.drafts:read']));

        $this->assertSame([], PeekResolver::drafts(null, []));
        $this->assertNull(PeekResolver::draftDiff(null, ['draftId' => $draft->id]));
    }

    public function testDraftsOutsideTheSchemasSectionsAreAbsent(): void
    {
        $release = $this->release();
        $draft = $this->draftInRelease($release);
        $this->schema($this->fullScope(['sections.' . $this->ensureSection()->uid . ':read']));

        $this->assertSame([], PeekResolver::drafts(null, []));
        $this->assertNull(PeekResolver::draftDiff(null, ['draftId' => $draft->id]));

        $row = PeekResolver::releaseById(null, ['id' => $release->id]);
        $this->assertSame('GraphQL release', $row['name'], 'The release itself is still visible.');
        $this->assertSame([], $row['entries']);
        $this->assertSame(0, $row['entryCount'], 'Hidden entries aren’t counted either.');
    }

    public function testDraftsOutsideTheSchemasSitesAreAbsent(): void
    {
        $release = $this->release();
        $draft = $this->draftInRelease($release);
        $this->schema($this->fullScope(['sites.' . Craft::$app->getSites()->getPrimarySite()->uid . ':read']));

        $this->assertSame([], PeekResolver::drafts(null, []));
        $this->assertSame([], PeekResolver::drafts(null, ['siteId' => $this->primarySiteId()]));
        $this->assertNull(PeekResolver::draftDiff(null, ['draftId' => $draft->id]));
        $this->assertSame([], PeekResolver::releases(null, []), 'A release on a site the schema can’t read is dropped.');
        $this->assertNull(PeekResolver::releaseById(null, ['id' => $release->id]));
    }

    public function testReleasesWithTheReleaseScope(): void
    {
        $release = $this->release();
        $draft = $this->draftInRelease($release);
        $this->schema($this->fullScope());

        $rows = PeekResolver::releases(null, []);
        $this->assertCount(1, $rows);
        $this->assertSame('peek', $rows[0]['source']);
        $this->assertSame('draft', $rows[0]['status']);
        $this->assertSame(1, $rows[0]['entryCount']);
        $this->assertSame([['canonicalId' => $draft->getCanonicalId(), 'draftId' => $draft->id, 'status' => 'pending']], $rows[0]['entries']);

        $this->assertSame([], PeekResolver::releases(null, ['status' => 'published']));
        $this->assertSame([], PeekResolver::releases(null, ['status' => 'nonsense']));
        $this->assertSame($release->id, PeekResolver::releaseById(null, ['id' => $release->id, 'source' => 'peek'])['id']);
        $this->assertNull(PeekResolver::releaseById(null, ['id' => $release->id, 'source' => 'craft']), 'Craft 5 has only Peek’s releases.');
        $this->assertNull(PeekResolver::releaseById(null, ['id' => 999999]));
    }

    public function testAQueryRunsThroughCraftsGraphQl(): void
    {
        $release = $this->release();
        $draft = $this->draftInRelease($release, 'Executed');
        $schema = $this->schema($this->fullScope());

        $result = Craft::$app->getGql()->executeQuery($schema, <<<GQL
{
  peekDrafts { id title sectionHandle stale dateUpdated }
  peekDraftDiff(draftId: {$draft->id}) { changedCount fields { handle changed oldValue newValue } draft { id } }
  peekRelease(id: {$release->id}) { name source entryCount entries { draftId status } }
}
GQL);

        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? null));
        $this->assertSame('Executed changed', $result['data']['peekDrafts'][0]['title']);
        $this->assertSame(3, $result['data']['peekDraftDiff']['changedCount']);
        $this->assertSame($draft->id, $result['data']['peekRelease']['entries'][0]['draftId']);
    }

    public function testAQueryWithoutTheScopeIsNotInTheSchema(): void
    {
        // Entries can be queried, so the schema isn't empty; Peek's scopes are missing.
        $schema = $this->schema($this->fullScope(['peekDrafts:read', 'peekReleases:read']));

        $fields = array_keys(Craft::$app->getGql()->getSchemaDef($schema, true)->getQueryType()->getFields());
        $this->assertContains('entries', $fields);
        $this->assertSame([], array_values(array_filter($fields, fn($f) => str_starts_with($f, 'peek'))));

        // Debug mode: otherwise Craft skips the "unknown field" rule and just returns no data.
        $result = Craft::$app->getGql()->executeQuery($schema, '{ peekReleases { id } }', debugMode: true);

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('peekReleases', $result['errors'][0]['message']);
    }

    /**
     * An Entries field (`related`) on the test entry type, and a Matrix field (`blocks`) whose
     * block type has its own Entries field (`blockRelated`).
     */
    private function addRelationFields(): void
    {
        $this->ensureSection();

        $related = new EntriesField(['name' => 'Related', 'handle' => 'related', 'sources' => '*']);
        $this->assertTrue(Craft::$app->getFields()->saveField($related), json_encode($related->getErrors()));
        $blockRelated = new EntriesField(['name' => 'Block Related', 'handle' => 'blockRelated', 'sources' => '*']);
        $this->assertTrue(Craft::$app->getFields()->saveField($blockRelated), json_encode($blockRelated->getErrors()));

        $blockLayout = new FieldLayout(['type' => Entry::class]);
        $blockLayout->setTabs([['name' => 'Content', 'elements' => [new CustomField($blockRelated)]]]);
        $blockType = new EntryType(['name' => 'Link Block', 'handle' => 'linkBlock', 'hasTitleField' => false]);
        $blockType->setFieldLayout($blockLayout);
        $this->assertTrue(Craft::$app->getEntries()->saveEntryType($blockType));

        $matrix = new Matrix(['name' => 'Blocks', 'handle' => 'blocks']);
        $matrix->setEntryTypes([$blockType]);
        $this->assertTrue(Craft::$app->getFields()->saveField($matrix), json_encode($matrix->getErrors()));

        $layout = $this->entryType->getFieldLayout();
        $tab = $layout->getTabs()[0];
        $tab->setElements([...$tab->getElements(), new CustomField($related), new CustomField($matrix)]);
        $layout->setTabs([$tab]);
        $this->entryType->setFieldLayout($layout);
        $this->assertTrue(Craft::$app->getEntries()->saveEntryType($this->entryType));
    }

    /**
     * @param int[] $ids
     */
    private function linkBlock(array $ids): array
    {
        return ['new1' => ['type' => 'linkBlock', 'enabled' => true, 'fields' => ['blockRelated' => $ids]]];
    }

    public function testRelationsInADiffAreCountsNotTitles(): void
    {
        $this->addRelationFields();
        $first = $this->createEntry('Secret target one');
        $second = $this->createEntry('Secret target two');
        $canonical = $this->createEntry('Relating', ['related' => [$first->id], 'blocks' => $this->linkBlock([$first->id])]);
        $draft = $this->createDraft($canonical, [], [
            'related' => [$second->id],
            'blocks' => $this->linkBlock([$first->id, $second->id]),
        ]);
        $this->schema($this->fullScope());

        $diff = PeekResolver::draftDiff(null, ['draftId' => $draft->id, 'includeUnchanged' => true]);
        $fields = array_column($diff['fields'], null, 'handle');

        // Swapping one related entry for another is still a change, though the counts match.
        $this->assertTrue($fields['related']['changed']);
        $this->assertSame('1 related element', $fields['related']['oldValue']);
        $this->assertSame('1 related element', $fields['related']['newValue']);

        $this->assertTrue($fields['blocks']['changed']);
        $this->assertStringContainsString('Block Related: 1 related element', $fields['blocks']['oldValue']);
        $this->assertStringContainsString('Block Related: 2 related elements', $fields['blocks']['newValue']);

        $json = json_encode($diff);
        foreach (['Secret target', "#{$first->id})", "#{$second->id})"] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }

        // The control panel and MCP still show what the relations are.
        $full = array_column(array_map(fn($d) => $d->toArray(), $this->diff()->diffEntry($draft, $draft->getCanonical())), null, 'handle');
        $this->assertSame("Secret target two (#{$second->id})", $full['related']['newValue']);
    }

    public function testAnEmptyRelationStaysNone(): void
    {
        $this->addRelationFields();
        $target = $this->createEntry('Secret target');
        $canonical = $this->createEntry('Was related', ['related' => [$target->id]]);
        $draft = $this->createDraft($canonical, [], ['related' => []]);
        $this->schema($this->fullScope());

        $fields = array_column(PeekResolver::draftDiff(null, ['draftId' => $draft->id])['fields'], null, 'handle');

        $this->assertSame('1 related element', $fields['related']['oldValue']);
        $this->assertSame('(none)', $fields['related']['newValue']);
    }

    public function testAnElementLinkIsCountedAndAUrlLinkIsShown(): void
    {
        $this->ensureSection();
        $link = new LinkField(['name' => 'Link', 'handle' => 'promoLink', 'types' => ['entry', 'url']]);
        $this->assertTrue(Craft::$app->getFields()->saveField($link), json_encode($link->getErrors()));
        $layout = $this->entryType->getFieldLayout();
        $tab = $layout->getTabs()[0];
        $tab->setElements([...$tab->getElements(), new CustomField($link)]);
        $layout->setTabs([$tab]);
        $this->entryType->setFieldLayout($layout);
        $this->assertTrue(Craft::$app->getEntries()->saveEntryType($this->entryType));

        $target = $this->createEntry('Secret link target');
        $canonical = $this->createEntry('Linking', ['promoLink' => ['type' => 'url', 'value' => 'https://example.com/public']]);
        $draft = $this->createDraft($canonical, [], ['promoLink' => ['type' => 'entry', 'value' => $target->id]]);
        $this->assertNotNull(Entry::find()->id($draft->id)->drafts(true)->status(null)->one()->getFieldValue('promoLink')?->getElement(), 'The draft should hold an element link, or this test proves nothing.');
        $this->schema($this->fullScope());

        $fields = array_column(PeekResolver::draftDiff(null, ['draftId' => $draft->id])['fields'], null, 'handle');

        $this->assertTrue($fields['promoLink']['changed']);
        $this->assertSame('https://example.com/public', $fields['promoLink']['oldValue']);
        $this->assertSame('1 linked element', $fields['promoLink']['newValue']);
        $this->assertStringNotContainsString('Secret', json_encode($fields));
        $this->assertStringNotContainsString((string)$target->id, (string)$fields['promoLink']['newValue']);
    }

    public function testAnyValueHoldingElementsIsCountedWhenRedacting(): void
    {
        $first = $this->createEntry('Secret one');
        $second = $this->createEntry('Secret two');
        $field = new PlainText(['name' => 'Anything', 'handle' => 'anything']);
        $serialize = new \ReflectionMethod($this->diff(), '_fieldValueToString');

        $redacted = fn(mixed $value) => $serialize->invoke($this->diff(), $field, $value, null, 0, true);

        // The JSON fallback would otherwise print the elements' attributes.
        $this->assertSame('2 related elements', $redacted(['items' => [$first, ['nested' => $second]]]));
        $this->assertSame('1 related element', $redacted($first));
        $this->assertSame('2 related elements', $redacted(collect([$first, $second])));
        $this->assertSame('1 related element', $redacted((object)['target' => $first]));
        $this->assertSame('(none)', $redacted(Entry::find()->id(0)));
        // Values without elements are shown as before.
        $this->assertSame('plain', $redacted('plain'));
        $this->assertStringContainsString('"a": 1', $redacted(['a' => 1]));
        $this->assertSame('[]', $redacted(collect([])), 'An empty collection holds nothing to hide.');

        // Arrays are shown only when every leaf is plain data.
        $table = [['col1' => 'Apple', 'col2' => 3], ['col1' => 'Pear', 'col2' => null]];
        $this->assertSame(json_encode($table, JSON_PRETTY_PRINT), $redacted($table));
        $this->assertStringContainsString('"when": "2026-11-23 10:00:00"', $redacted([['when' => new \DateTime('2026-11-23 10:00:00')]]));
        $leaky = new class() implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['secret' => 'value'];
            }
        };
        $this->assertSame('(not shown over GraphQL)', $redacted([['cell' => $leaky]]));
        $this->assertSame('(not shown over GraphQL)', $redacted(collect(['a', $leaky])));
        $this->assertStringNotContainsString('too deep', $redacted([[[[[['too deep']]]]]]), 'Past the depth cap, fail closed.');
        $this->assertSame('(not shown over GraphQL)', $redacted([[[[['deep', $leaky]]]]]));
        $this->assertSame('2026-11-23 10:00:00', $redacted(new \DateTime('2026-11-23 10:00:00')));

        // A third-party object without elements still isn't shown: Peek can't vouch for what's in it.
        $this->assertSame('(not shown over GraphQL)', $redacted((object)['secret' => 'value']));
        $this->assertSame('(not shown over GraphQL)', $redacted(new \craft\models\Section()));
        $this->assertSame('(not shown over GraphQL)', $redacted(new class() implements \Stringable {
            public function __toString(): string
            {
                return 'RENDERED SECRET';
            }
        }));

        // Rich text is read from its stored HTML, never rendered, and loses its reference tags.
        $richText = new class() implements \Stringable {
            public function getRawContent(): string
            {
                return '<p>Hi <a href="{entry:1@1:url}">x</a></p>';
            }

            public function __toString(): string
            {
                return 'RENDERED SECRET';
            }
        };
        $text = $redacted($richText);
        $this->assertStringContainsString('Hi x', $text);
        $this->assertStringNotContainsString('RENDERED SECRET', $text);
        $this->assertStringNotContainsString('{entry', $text);
        $this->assertSame('RENDERED SECRET', $serialize->invoke($this->diff(), $field, $richText, null, 0, false), 'Unredacted (CP, MCP) is unchanged.');
        $this->assertSame('(not shown over GraphQL)', $redacted(new class() implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['secret' => 'value'];
            }
        }));

        $this->assertStringContainsString('Secret one', $serialize->invoke($this->diff(), $field, [$first], null, 0, false), 'Unredacted (CP, MCP) is unchanged.');
    }
}
