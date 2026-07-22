<?php

namespace justinholtweb\peek\tests\integration;

use justinholtweb\peek\models\FieldDiff;
use justinholtweb\peek\tests\Support\PeekTestCase;

/**
 * Exercises DiffService against real draft/canonical pairs — the parts that
 * need a live field layout and element, which the unit suite can't reach.
 */
class DiffEntryTest extends PeekTestCase
{
    /**
     * @param FieldDiff[] $diffs
     */
    private function byHandle(array $diffs, string $handle): FieldDiff
    {
        foreach ($diffs as $diff) {
            if ($diff->handle === $handle) {
                return $diff;
            }
        }

        $this->fail("No diff produced for '$handle'. Got: " . implode(', ', array_map(fn($d) => $d->handle, $diffs)));
    }

    public function testAnUnchangedDraftReportsNoChanges(): void
    {
        $canonical = $this->createEntry('Same', ['body' => 'same body']);
        $draft = $this->createDraft($canonical);

        $diffs = $this->diff()->diffEntry($draft, $canonical);

        $this->assertNotEmpty($diffs);
        foreach ($diffs as $diff) {
            $this->assertFalse($diff->hasChanges, "Expected no change for '{$diff->handle}'.");
            $this->assertNull($diff->diffHtml);
        }
    }

    public function testEveryNativeAttributeAndCustomFieldIsCovered(): void
    {
        $canonical = $this->createEntry('Coverage', ['body' => 'body']);
        $draft = $this->createDraft($canonical);

        $handles = array_map(fn($d) => $d->handle, $this->diff()->diffEntry($draft, $canonical));

        $this->assertContains('title', $handles);
        $this->assertContains('slug', $handles);
        $this->assertContains('enabled', $handles);
        $this->assertContains('body', $handles);
    }

    public function testChangedTitleIsDiffed(): void
    {
        $canonical = $this->createEntry('Before title');
        $draft = $this->createDraft($canonical, ['title' => 'After title']);

        $diff = $this->byHandle($this->diff()->diffEntry($draft, $canonical), 'title');

        $this->assertTrue($diff->hasChanges);
        $this->assertSame('Before title', $diff->oldValue);
        $this->assertSame('After title', $diff->newValue);
        $this->assertSame('native', $diff->type);
        $this->assertNotEmpty($diff->diffHtml);
        $this->assertStringContainsString('<table', $diff->diffHtml);
    }

    public function testChangedSlugIsDiffed(): void
    {
        $canonical = $this->createEntry('Slugged');
        $draft = $this->createDraft($canonical, ['slug' => 'a-brand-new-slug']);

        $diff = $this->byHandle($this->diff()->diffEntry($draft, $canonical), 'slug');

        $this->assertTrue($diff->hasChanges);
        $this->assertSame('a-brand-new-slug', $diff->newValue);
    }

    public function testChangedEnabledStateRendersAsABooleanDiff(): void
    {
        $canonical = $this->createEntry('Toggled');
        $draft = $this->createDraft($canonical, ['enabled' => false]);

        $diff = $this->byHandle($this->diff()->diffEntry($draft, $canonical), 'enabled');

        $this->assertTrue($diff->hasChanges);
        $this->assertTrue($diff->oldValue);
        $this->assertFalse($diff->newValue);
        $this->assertStringContainsString('peek-boolean-diff', $diff->diffHtml);
        $this->assertStringContainsString('On', $diff->diffHtml);
        $this->assertStringContainsString('Off', $diff->diffHtml);
    }

    public function testChangedCustomFieldIsDiffed(): void
    {
        $canonical = $this->createEntry('Fielded', ['body' => 'the old body']);
        $draft = $this->createDraft($canonical, [], ['body' => 'the new body']);

        $diff = $this->byHandle($this->diff()->diffEntry($draft, $canonical), 'body');

        $this->assertTrue($diff->hasChanges);
        $this->assertSame('the old body', $diff->oldValue);
        $this->assertSame('the new body', $diff->newValue);
        $this->assertSame('Body', $diff->label);
        $this->assertNotEmpty($diff->diffHtml);
    }

    public function testFillingAnEmptyFieldIsAChange(): void
    {
        $canonical = $this->createEntry('Empty start');
        $draft = $this->createDraft($canonical, [], ['body' => 'now it has content']);

        $diff = $this->byHandle($this->diff()->diffEntry($draft, $canonical), 'body');

        $this->assertTrue($diff->hasChanges);
        $this->assertSame('', $diff->oldValue);
        $this->assertSame('now it has content', $diff->newValue);
    }

    public function testClearingAFieldIsAChange(): void
    {
        $canonical = $this->createEntry('Full start', ['body' => 'goodbye']);
        $draft = $this->createDraft($canonical, [], ['body' => '']);

        $diff = $this->byHandle($this->diff()->diffEntry($draft, $canonical), 'body');

        $this->assertTrue($diff->hasChanges);
        $this->assertSame('goodbye', $diff->oldValue);
        $this->assertSame('', $diff->newValue);
    }

    public function testChangingOneFieldLeavesTheOthersUnchanged(): void
    {
        $canonical = $this->createEntry('Only title moves', ['body' => 'steady']);
        $draft = $this->createDraft($canonical, ['title' => 'Title moved']);

        $diffs = $this->diff()->diffEntry($draft, $canonical);

        $this->assertTrue($this->byHandle($diffs, 'title')->hasChanges);
        $this->assertFalse($this->byHandle($diffs, 'body')->hasChanges);
        $this->assertFalse($this->byHandle($diffs, 'enabled')->hasChanges);
    }

    public function testPreviewUrlsAreNullForASectionWithoutUrls(): void
    {
        $canonical = $this->createEntry('No URLs');
        $draft = $this->createDraft($canonical, ['title' => 'No URLs draft']);

        $urls = $this->diff()->getPreviewUrls($draft, $canonical);

        $this->assertArrayHasKey('canonical', $urls);
        $this->assertArrayHasKey('draft', $urls);
        $this->assertNull($urls['canonical']);
        $this->assertNull($urls['draft']);
    }
}
