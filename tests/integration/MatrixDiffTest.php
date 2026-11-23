<?php

namespace justinholtweb\peek\tests\integration;

use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use justinholtweb\peek\models\FieldDiff;
use justinholtweb\peek\tests\Support\PeekTestCase;

/**
 * Matrix blocks are compared by content. A draft gets its own copy of every block it touches,
 * with a new ID, so diffing by ID (before 5.1.2) called identical blocks "changed" and showed
 * only "(#id)" lines (issue #3).
 */
class MatrixDiffTest extends PeekTestCase
{
    private function addMatrixField(): void
    {
        $this->ensureSection();

        $heading = new PlainText(['name' => 'Section Heading', 'handle' => 'sectionHeading']);
        $this->assertTrue(Craft::$app->getFields()->saveField($heading));

        $blockLayout = new FieldLayout(['type' => Entry::class]);
        $blockLayout->setTabs([['name' => 'Content', 'elements' => [new CustomField($heading)]]]);
        $blockType = new EntryType(['name' => 'Text Grid', 'handle' => 'textGrid', 'hasTitleField' => false]);
        $blockType->setFieldLayout($blockLayout);
        $this->assertTrue(Craft::$app->getEntries()->saveEntryType($blockType));

        $matrix = new Matrix(['name' => 'Content Blocks', 'handle' => 'contentBlocks']);
        $matrix->setEntryTypes([$blockType]);
        $this->assertTrue(Craft::$app->getFields()->saveField($matrix), json_encode($matrix->getErrors()));

        $layout = $this->entryType->getFieldLayout();
        $tab = $layout->getTabs()[0];
        $tab->setElements([...$tab->getElements(), new CustomField($matrix)]);
        $layout->setTabs([$tab]);
        $this->entryType->setFieldLayout($layout);
        $this->assertTrue(Craft::$app->getEntries()->saveEntryType($this->entryType));
    }

    /**
     * @param string[] $headings
     */
    private function blocks(array $headings): array
    {
        $blocks = [];
        foreach ($headings as $i => $heading) {
            $blocks['new' . ($i + 1)] = [
                'type' => 'textGrid',
                'enabled' => true,
                'fields' => ['sectionHeading' => $heading],
            ];
        }
        return $blocks;
    }

    private function blocksDiff(Entry $draft, Entry $canonical): FieldDiff
    {
        foreach ($this->diff()->diffEntry($draft, $canonical) as $diff) {
            if ($diff->handle === 'contentBlocks') {
                return $diff;
            }
        }
        $this->fail('No diff for contentBlocks.');
    }

    public function testARecreatedBlockWithTheSameContentIsNotAChange(): void
    {
        $this->addMatrixField();
        $canonical = $this->createEntry('Matrix same', ['contentBlocks' => $this->blocks(['Majors & Minors'])]);
        $draft = $this->createDraft($canonical, [], ['contentBlocks' => $this->blocks(['Majors & Minors'])]);

        $canonicalIds = Entry::find()->owner($canonical)->fieldId(Craft::$app->getFields()->getFieldByHandle('contentBlocks')->id)->status(null)->ids();
        $draftIds = Entry::find()->owner($draft)->fieldId(Craft::$app->getFields()->getFieldByHandle('contentBlocks')->id)->status(null)->ids();
        $this->assertNotEquals($canonicalIds, $draftIds, 'The draft should hold its own copy of the block, or this test proves nothing.');

        $diff = $this->blocksDiff($draft, $canonical);

        $this->assertFalse($diff->hasChanges, "Old:\n{$diff->oldValue}\nNew:\n{$diff->newValue}");
        $this->assertStringContainsString('Text Grid', $diff->oldValue);
        $this->assertStringContainsString('Section Heading: Majors & Minors', $diff->oldValue);
        $this->assertStringNotContainsString('(#', $diff->oldValue);
    }

    public function testAChangedBlockShowsItsContent(): void
    {
        $this->addMatrixField();
        $canonical = $this->createEntry('Matrix changed', ['contentBlocks' => $this->blocks(['One', 'Two'])]);
        $draft = $this->createDraft($canonical, [], ['contentBlocks' => $this->blocks(['One', 'Two, edited'])]);

        $diff = $this->blocksDiff($draft, $canonical);

        $this->assertTrue($diff->hasChanges);
        $this->assertStringContainsString("#2 Text Grid\n    Section Heading: Two", $diff->oldValue);
        $this->assertStringContainsString('Section Heading: Two, edited', $diff->newValue);
        $this->assertStringContainsString('edited', (string)$diff->diffHtml);
    }

    public function testAnUntouchedDraftSharesItsBlocksAndReportsNoChange(): void
    {
        $this->addMatrixField();
        $canonical = $this->createEntry('Matrix untouched', ['contentBlocks' => $this->blocks(['Only'])]);
        $draft = $this->createDraft($canonical, ['title' => 'Matrix untouched, retitled']);

        $this->assertFalse($this->blocksDiff($draft, $canonical)->hasChanges);
    }

    /**
     * The entry editor's element can hold eager-loaded collections; the diff page's never does.
     */
    public function testEagerLoadedRelationsReadTheSameAsAQuery(): void
    {
        $this->addMatrixField();
        $canonical = $this->createEntry('Eager', ['contentBlocks' => $this->blocks(['A'])]);
        $draft = $this->createDraft($canonical);

        $eager = Entry::find()->id($draft->id)->drafts(true)->status(null)->with(['contentBlocks'])->one();

        $this->assertSame(
            $this->blocksDiff($draft, $canonical)->newValue,
            $this->blocksDiff($eager, $canonical)->newValue,
        );
    }
}
