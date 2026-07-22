<?php

namespace justinholtweb\peek\tests\Support;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\test\TestCase;
use justinholtweb\peek\Plugin;
use justinholtweb\peek\services\DiffService;
use justinholtweb\peek\services\DraftService;
use justinholtweb\peek\services\Releases;

/**
 * Base class for Peek's integration tests.
 *
 * Each test gets a real Craft install with a single "Test" channel section
 * carrying one plain text field, which is enough to exercise both the native
 * and custom-field halves of the diff. The Craft module wraps every test in a
 * transaction, so anything created here disappears afterwards.
 */
abstract class PeekTestCase extends TestCase
{
    protected ?Section $section = null;
    protected ?EntryType $entryType = null;
    protected ?PlainText $bodyField = null;

    protected function releases(): Releases
    {
        return Plugin::getInstance()->releases;
    }

    protected function diff(): DiffService
    {
        return Plugin::getInstance()->diff;
    }

    protected function draftService(): DraftService
    {
        return Plugin::getInstance()->drafts;
    }

    protected function primarySiteId(): int
    {
        return Craft::$app->getSites()->getPrimarySite()->id;
    }

    protected function adminId(): int
    {
        $user = User::find()->admin()->one();
        self::assertNotNull($user, 'The test install should have an admin user.');

        return $user->id;
    }

    /**
     * Creates a channel section (plus entry type and a `body` field) on demand.
     */
    protected function ensureSection(string $handle = 'testSection'): Section
    {
        if ($this->section !== null) {
            return $this->section;
        }

        $this->bodyField = new PlainText([
            'name' => 'Body',
            'handle' => 'body',
            'multiline' => true,
        ]);
        self::assertTrue(Craft::$app->getFields()->saveField($this->bodyField), 'Could not save the body field.');

        $fieldLayout = new FieldLayout(['type' => Entry::class]);
        $fieldLayout->setTabs([
            [
                'name' => 'Content',
                'elements' => [
                    // Craft derives EntryType::$hasTitleField from the layout, so
                    // the title field has to be in it explicitly.
                    new EntryTitleField(),
                    new CustomField($this->bodyField),
                ],
            ],
        ]);

        $this->entryType = new EntryType([
            'name' => 'Test Type',
            'handle' => $handle . 'Type',
            // Craft 5 defaults this to false; the diff tests need a real title.
            'hasTitleField' => true,
        ]);
        $this->entryType->setFieldLayout($fieldLayout);
        self::assertTrue(Craft::$app->getEntries()->saveEntryType($this->entryType), 'Could not save the entry type.');

        $section = new Section([
            'name' => 'Test Section',
            'handle' => $handle,
            'type' => Section::TYPE_CHANNEL,
            'propagationMethod' => PropagationMethod::All,
            'siteSettings' => [
                new Section_SiteSettings([
                    'siteId' => $this->primarySiteId(),
                    'enabledByDefault' => true,
                    'hasUrls' => false,
                ]),
            ],
        ]);
        $section->setEntryTypes([$this->entryType]);

        self::assertTrue(Craft::$app->getEntries()->saveSection($section), 'Could not save the section.');
        $this->section = $section;

        return $section;
    }

    /**
     * Creates and saves a live canonical entry.
     *
     * @param array<string, mixed> $customFields
     */
    protected function createEntry(string $title = 'Canonical', array $customFields = []): Entry
    {
        $section = $this->ensureSection();

        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $this->entryType->id,
            'siteId' => $this->primarySiteId(),
            'authorId' => $this->adminId(),
            'title' => $title,
            'enabled' => true,
        ]);

        foreach ($customFields as $handle => $value) {
            $entry->setFieldValue($handle, $value);
        }

        self::assertTrue(
            Craft::$app->getElements()->saveElement($entry),
            'Could not save entry: ' . json_encode($entry->getErrors()),
        );

        return $entry;
    }

    /**
     * Creates a saved (non-provisional) draft of an entry, optionally applying
     * changes to it.
     *
     * @param array<string, mixed> $attributes Native attributes to change (e.g. title, slug, enabled).
     * @param array<string, mixed> $customFields Custom field values to change.
     */
    protected function createDraft(Entry $canonical, array $attributes = [], array $customFields = []): Entry
    {
        /** @var Entry $draft */
        $draft = Craft::$app->getDrafts()->createDraft($canonical, $this->adminId());

        // Re-fetch so the draft is fully hydrated the way the CP would load it.
        $draft = Entry::find()->id($draft->id)->drafts(true)->status(null)->siteId($canonical->siteId)->one();
        self::assertNotNull($draft, 'Draft was not retrievable after creation.');

        if ($attributes !== [] || $customFields !== []) {
            foreach ($attributes as $name => $value) {
                $draft->$name = $value;
            }
            foreach ($customFields as $handle => $value) {
                $draft->setFieldValue($handle, $value);
            }

            self::assertTrue(
                Craft::$app->getElements()->saveElement($draft),
                'Could not save draft: ' . json_encode($draft->getErrors()),
            );
        }

        return $draft;
    }
}
