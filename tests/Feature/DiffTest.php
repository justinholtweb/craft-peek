<?php

declare(strict_types=1);

use CraftCms\Cms\Cms;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\RouteToken\RouteTokens;
use CraftCms\Cms\Support\Facades\Fields;
use Illuminate\Contracts\Support\Arrayable;
use JustinHoltWeb\Peek\Data\FieldDiff;
use JustinHoltWeb\Peek\Services\Diff;

function diffFor(array $diffs, string $handle): FieldDiff
{
    foreach ($diffs as $diff) {
        if ($diff->handle === $handle) {
            return $diff;
        }
    }

    throw new RuntimeException("No diff for {$handle}");
}

it('reports no changes for an untouched draft', function () {
    $canonical = $this->createEntry('Same', ['body' => 'same body']);
    $draft = $this->createDraft($canonical);

    $diffs = app(Diff::class)->diffEntry($draft, $canonical);

    expect($diffs)->not->toBeEmpty();
    foreach ($diffs as $diff) {
        expect($diff->hasChanges)->toBeFalse("{$diff->handle} changed");
    }
});

it('diffs a changed title and body word by word', function () {
    $canonical = $this->createEntry('Before', ['body' => 'the old body']);
    $draft = $this->createDraft($canonical, ['title' => 'After'], ['body' => 'the new body']);

    $diffs = app(Diff::class)->diffEntry($draft, $canonical);

    expect(diffFor($diffs, 'title'))
        ->hasChanges->toBeTrue()
        ->oldValue->toBe('Before')
        ->newValue->toBe('After')
        ->and(diffFor($diffs, 'body'))
        ->hasChanges->toBeTrue()
        ->diffHtml->toContain('<table');
});

it('renders a changed enabled state as a boolean diff', function () {
    $canonical = $this->createEntry('Toggled');
    $draft = $this->createDraft($canonical, ['enabled' => false]);

    expect(diffFor(app(Diff::class)->diffEntry($draft, $canonical), 'enabled'))
        ->hasChanges->toBeTrue()
        ->diffHtml->toContain('peek-boolean-diff');
});

it('compares Matrix blocks by content, not by ID (issue #3)', function () {
    $canonical = $this->createEntry('Matrix same', ['blocks' => $this->blocks(['Majors & Minors'])]);
    $draft = $this->createDraft($canonical, [], ['blocks' => $this->blocks(['Majors & Minors'])]);

    $field = Fields::getFieldByHandle('blocks');
    $canonicalIds = Entry::find()->owner($canonical)->fieldId($field->id)->status(null)->ids();
    $draftIds = Entry::find()->owner($draft)->fieldId($field->id)->status(null)->ids();
    expect($draftIds)->not->toEqual($canonicalIds, 'The draft should hold its own copy of the block, or this proves nothing.');

    $diff = diffFor(app(Diff::class)->diffEntry($draft, $canonical), 'blocks');

    expect($diff->hasChanges)->toBeFalse("Old:\n{$diff->oldValue}\nNew:\n{$diff->newValue}")
        ->and($diff->oldValue)->toContain('#1 Text Grid')
        ->and($diff->oldValue)->toContain('Section Heading: Majors & Minors')
        ->and($diff->oldValue)->not->toContain('(#');
});

it('shows a changed Matrix block by its content', function () {
    $canonical = $this->createEntry('Matrix changed', ['blocks' => $this->blocks(['One', 'Two'])]);
    $draft = $this->createDraft($canonical, [], ['blocks' => $this->blocks(['One', 'Two, edited'])]);

    $diff = diffFor(app(Diff::class)->diffEntry($draft, $canonical), 'blocks');

    expect($diff->hasChanges)->toBeTrue()
        ->and($diff->oldValue)->toContain("#2 Text Grid\n    Section Heading: Two")
        ->and($diff->newValue)->toContain('Section Heading: Two, edited')
        ->and((string) $diff->diffHtml)->toContain('edited');
});

it('reads eager-loaded Matrix values the same as a query', function () {
    $canonical = $this->createEntry('Eager', ['blocks' => $this->blocks(['A'])]);
    $draft = $this->createDraft($canonical);

    $eager = Entry::find()->id($draft->id)->drafts()->status(null)->with(['blocks'])->one();

    expect(diffFor(app(Diff::class)->diffEntry($eager, $canonical), 'blocks')->newValue)
        ->toBe(diffFor(app(Diff::class)->diffEntry($draft, $canonical), 'blocks')->newValue);
});

it('compares objects with no string form as JSON (issue #1)', function () {
    $field = new PlainText(['handle' => 'x', 'name' => 'X']);
    $model = new class implements Arrayable
    {
        public function toArray(): array
        {
            return ['title' => 'SEO title'];
        }
    };

    expect(app(Diff::class)->valueToString($field, $model))->toContain('"title": "SEO title"')
        ->and(app(Diff::class)->valueToString($field, new stdClass))->toBe('{}');
});

it('gives the draft pane a preview token for the draft, not the live URL (issue #2)', function () {
    $canonical = $this->createEntry('Has a URL');
    $draft = $this->createDraft($canonical, ['title' => 'Has a URL draft']);

    $urls = app(Diff::class)->previewUrls($draft, $canonical);

    expect($urls['canonical'])->toBe($canonical->getUrl())
        ->and($urls['draft'])->toStartWith($draft->getUrl().'?');

    parse_str((string) parse_url((string) $urls['draft'], PHP_URL_QUERY), $params);
    $tokenParam = Cms::config()->tokenParam;

    expect($params)->toHaveKey($tokenParam)->toHaveKey('x-craft-preview');

    $route = app(RouteTokens::class)->getTokenRoute($params[$tokenParam]);
    expect($route[1]['draftId'])->toBe($draft->draftId)
        ->and($route[1]['canonicalId'])->toBe($canonical->id);
});
