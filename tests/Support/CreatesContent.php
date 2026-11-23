<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Tests\Support;

use CraftCms\Cms\Edition;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\LayoutElements\Entries\EntryTitleField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\Support\Facades\UserPermissions;
use CraftCms\Cms\User\Elements\User;

/**
 * Real content for the tests: a channel section with a title, a plain text `body` and a Matrix
 * `blocks` field (one block type, `textGrid`, with a `heading`), entries, drafts and users,
 * all saved through Craft's own services.
 */
trait CreatesContent
{
    protected ?Section $section = null;

    protected ?EntryType $entryType = null;

    protected function section(): Section
    {
        if ($this->section !== null) {
            return $this->section;
        }

        $body = new PlainText(['name' => 'Body', 'handle' => 'body', 'multiline' => true]);
        expect(Fields::saveField($body))->toBeTrue();

        $heading = new PlainText(['name' => 'Section Heading', 'handle' => 'heading']);
        expect(Fields::saveField($heading))->toBeTrue();

        $blockType = new EntryType(['name' => 'Text Grid', 'handle' => 'textGrid']);
        $blockLayout = new FieldLayout(['type' => Entry::class]);
        $blockLayout->setTabs([['name' => 'Content', 'elements' => [new CustomField($heading)]]]);
        $blockType->setFieldLayout($blockLayout);
        expect(EntryTypes::saveEntryType($blockType))->toBeTrue();

        $blocks = new Matrix(['name' => 'Content Blocks', 'handle' => 'blocks']);
        $blocks->setEntryTypes([$blockType]);
        expect(Fields::saveField($blocks))->toBeTrue();

        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([[
            'name' => 'Content',
            'elements' => [new EntryTitleField, new CustomField($body), new CustomField($blocks)],
        ]]);

        $this->entryType = new EntryType(['name' => 'Article', 'handle' => 'article']);
        $this->entryType->setFieldLayout($layout);
        expect(EntryTypes::saveEntryType($this->entryType))->toBeTrue();

        $section = new Section([
            'name' => 'News',
            'handle' => 'news',
            'type' => SectionType::Channel,
            'siteSettings' => [
                new SectionSiteSettings([
                    'siteId' => Sites::getPrimarySite()->id,
                    'enabledByDefault' => true,
                    'hasUrls' => true,
                    'uriFormat' => 'news/{slug}',
                    'template' => 'news/_entry',
                ]),
            ],
        ]);
        $section->setEntryTypes([$this->entryType]);
        expect(Sections::saveSection($section))->toBeTrue();

        return $this->section = $section;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function createEntry(string $title = 'Canonical', array $fields = []): Entry
    {
        $section = $this->section();

        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $this->entryType->id,
            'siteId' => Sites::getPrimarySite()->id,
            'title' => $title,
            'enabled' => true,
        ]);
        $entry->setAuthorIds([$this->admin()->id]);

        foreach ($fields as $handle => $value) {
            $entry->setFieldValue($handle, $value);
        }

        expect(Elements::saveElement($entry))->toBeTrue(json_encode($entry->errors()->getMessages()) ?: '');

        return $entry;
    }

    /**
     * A saved (non-provisional) draft, reloaded the way the CP loads it, with optional changes.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $fields
     */
    protected function createDraft(Entry $canonical, array $attributes = [], array $fields = [], ?User $creator = null): Entry
    {
        $draft = Drafts::createDraft($canonical, ($creator ?? $this->admin())->id);
        $draft = $this->reloadDraft((int) $draft->id);

        if ($attributes !== [] || $fields !== []) {
            foreach ($attributes as $name => $value) {
                $draft->$name = $value;
            }

            foreach ($fields as $handle => $value) {
                $draft->setFieldValue($handle, $value);
            }

            expect(Elements::saveElement($draft))->toBeTrue(json_encode($draft->errors()->getMessages()) ?: '');
            $draft = $this->reloadDraft((int) $draft->id);
        }

        return $draft;
    }

    protected function reloadDraft(int $id): Entry
    {
        $draft = Entry::find()->id($id)->drafts()->provisionalDrafts(null)->status(null)->one();
        expect($draft)->toBeInstanceOf(Entry::class);

        return $draft;
    }

    /**
     * Matrix blocks in Craft's serialized form.
     *
     * @param  list<string>  $headings
     * @return array<string, array<string, mixed>>
     */
    protected function blocks(array $headings): array
    {
        $blocks = [];

        foreach ($headings as $i => $heading) {
            $blocks['new'.($i + 1)] = ['type' => 'textGrid', 'enabled' => true, 'fields' => ['heading' => $heading]];
        }

        return $blocks;
    }

    protected function admin(): User
    {
        $user = User::find()->admin()->one();
        expect($user)->toBeInstanceOf(User::class);

        return $user;
    }

    /**
     * An active, non-admin user with exactly these permissions. `{section}` in a permission
     * name is replaced with the test section's UID.
     *
     * @param  list<string>  $permissions
     */
    protected function userWith(array $permissions, string $username = 'editor'): User
    {
        $user = new User([
            'username' => $username,
            'email' => $username.'@example.com',
            'active' => true,
        ]);
        expect(Elements::saveElement($user))->toBeTrue(json_encode($user->errors()->getMessages()) ?: '');

        $uid = $this->section()->uid;
        $permissions = array_map(fn (string $p) => str_replace('{section}', (string) $uid, $p), ['accessCp', ...$permissions]);

        // On Team every non-admin shares the one Team group's permissions; per-user ones are Pro.
        if (Edition::get() === Edition::Team) {
            UserPermissions::saveGroupPermissions(UserGroups::getTeamGroup()->id, $permissions);
        } else {
            UserPermissions::saveUserPermissions($user->id, $permissions);
        }

        return User::find()->id($user->id)->status(null)->one();
    }
}
