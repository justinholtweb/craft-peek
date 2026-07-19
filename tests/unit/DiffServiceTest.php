<?php

namespace justinholtweb\peek\tests\unit;

use Codeception\Test\Unit;
use craft\fields\Date as DateField;
use craft\fields\Email as EmailField;
use craft\fields\Lightswitch as LightswitchField;
use craft\fields\PlainText;
use DateTime;
use justinholtweb\peek\services\DiffService;
use ReflectionMethod;

/**
 * Covers the value-serialization and rendering helpers that turn arbitrary
 * field values into the comparable strings the diff view depends on. These are
 * the pieces of DiffService that don't require a live Craft element/DB.
 */
class DiffServiceTest extends Unit
{
    private function invokePrivate(string $method, array $args): mixed
    {
        $service = new DiffService();
        $ref = new ReflectionMethod($service, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($service, $args);
    }

    public function testEmptyRelationsRenderAsNone(): void
    {
        $this->assertSame('(none)', $this->invokePrivate('_relationsToString', [[]]));
    }

    public function testNonRelationValueRendersEmpty(): void
    {
        $this->assertSame('', $this->invokePrivate('_relationsToString', ['not-a-relation']));
    }

    public function testRelationsListLabelsAndIds(): void
    {
        $elements = [
            (object)['title' => 'Home', 'id' => 12],
            (object)['title' => 'About', 'id' => 34],
        ];

        $result = $this->invokePrivate('_relationsToString', [$elements]);

        $this->assertSame("Home (#12)\nAbout (#34)", $result);
    }

    public function testEmptyAssetsRenderAsNone(): void
    {
        $this->assertSame('(none)', $this->invokePrivate('_assetsToString', [[]]));
    }

    public function testNonAssetElementsFallBackToTitle(): void
    {
        $elements = [
            (object)['title' => 'Some Entry', 'id' => 7],
        ];

        $result = $this->invokePrivate('_assetsToString', [$elements]);

        $this->assertSame('Some Entry (#7)', $result);
    }

    public function testBooleanDiffRendersOnOffTransition(): void
    {
        $html = $this->invokePrivate('_renderBooleanDiff', [false, true]);

        $this->assertStringContainsString('Off', $html);
        $this->assertStringContainsString('On', $html);
        $this->assertStringContainsString('peek-diff-removed', $html);
        $this->assertStringContainsString('peek-diff-added', $html);
    }

    public function testTextDiffOfTwoEmptyStringsIsEmpty(): void
    {
        $this->assertSame('', $this->invokePrivate('_renderTextDiff', ['', '']));
    }

    public function testTextDiffProducesOutputForChange(): void
    {
        $html = $this->invokePrivate('_renderTextDiff', ['hello world', 'hello there']);

        $this->assertNotEmpty($html);
    }

    public function testTextDiffRendersSideBySideTable(): void
    {
        $html = $this->invokePrivate('_renderTextDiff', ['alpha', 'omega']);

        $this->assertStringContainsString('<table', $html);
    }

    public function testBooleanDiffRendersOffOnReverseTransition(): void
    {
        $html = $this->invokePrivate('_renderBooleanDiff', [true, false]);

        // Removed side is the old value (On), added side is the new value (Off).
        $this->assertStringContainsString('peek-diff-removed">On', $html);
        $this->assertStringContainsString('peek-diff-added">Off', $html);
    }

    public function testRelationsFallBackToNameWhenTitleMissing(): void
    {
        // Users and categories expose `name`, not `title`.
        $elements = [
            (object)['name' => 'Ada Lovelace', 'id' => 5],
        ];

        $this->assertSame(
            'Ada Lovelace (#5)',
            $this->invokePrivate('_relationsToString', [$elements]),
        );
    }

    public function testLightswitchSerializesToOnOff(): void
    {
        $field = new LightswitchField();

        $this->assertSame('', $this->serialize($field, null));
        $this->assertSame('On', $this->serialize($field, true));
        $this->assertSame('On', $this->serialize($field, '1'));
        $this->assertSame('Off', $this->serialize($field, '0'));
        $this->assertSame('Off', $this->serialize($field, 0));
    }

    public function testDateFieldSerializesToTimestamp(): void
    {
        $field = new DateField();

        $this->assertSame(
            '2024-01-02 03:04:05',
            $this->serialize($field, new DateTime('2024-01-02 03:04:05')),
        );
    }

    public function testEmailFieldSerializesStringAndDropsNonString(): void
    {
        $field = new EmailField();

        $this->assertSame('a@b.com', $this->serialize($field, 'a@b.com'));
        $this->assertSame('', $this->serialize($field, 123));
    }

    public function testPlainTextGenericBranches(): void
    {
        $field = new PlainText();

        $this->assertSame('', $this->serialize($field, null));
        $this->assertSame('hello', $this->serialize($field, 'hello'));
        $this->assertSame('On', $this->serialize($field, true));
        $this->assertSame('42', $this->serialize($field, 42));
        $this->assertSame('3.14', $this->serialize($field, 3.14));
        $this->assertSame(
            '2024-05-06 07:08:09',
            $this->serialize($field, new DateTime('2024-05-06 07:08:09')),
        );
    }

    public function testPlainTextSerializesArrayAsJson(): void
    {
        $result = $this->serialize(new PlainText(), ['a' => 1, 'b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], json_decode($result, true));
    }

    public function testObjectValuesAreStrippedOfHtmlForTextDiff(): void
    {
        $value = new class() {
            public function __toString(): string
            {
                return '<p>Hi <b>there</b></p>';
            }
        };

        $this->assertSame('Hi there', $this->serialize(new PlainText(), $value));
    }

    private function serialize(mixed $field, mixed $value): string
    {
        return $this->invokePrivate('_fieldValueToString', [$field, $value]);
    }
}
