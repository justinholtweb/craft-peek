<?php

namespace justinholtweb\peek\tests\unit;

use Codeception\Test\Unit;
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
}
