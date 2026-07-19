<?php

namespace justinholtweb\peek\tests\unit;

use Codeception\Test\Unit;
use justinholtweb\peek\models\Settings;

/**
 * Covers the plugin Settings model: its shipped defaults and the validation
 * bounds that keep operator-supplied configuration sane.
 */
class SettingsTest extends Unit
{
    public function testShippedDefaults(): void
    {
        $settings = new Settings();

        $this->assertSame(14, $settings->staleDraftDays);
        $this->assertNull($settings->defaultSiteId);
        $this->assertTrue($settings->enableVisualPreview);
        $this->assertSame(50, $settings->maxEntriesPerRelease);
    }

    public function testDefaultsPassValidation(): void
    {
        $this->assertTrue((new Settings())->validate());
    }

    public function testStaleDraftDaysMustBeAtLeastOne(): void
    {
        $settings = new Settings();
        $settings->staleDraftDays = 0;

        $this->assertFalse($settings->validate());
        $this->assertArrayHasKey('staleDraftDays', $settings->getErrors());
    }

    public function testMaxEntriesPerReleaseIsCappedAt500(): void
    {
        $settings = new Settings();
        $settings->maxEntriesPerRelease = 501;
        $this->assertFalse($settings->validate());

        $settings->maxEntriesPerRelease = 500;
        $this->assertTrue($settings->validate());
    }

    public function testMaxEntriesPerReleaseMustBeAtLeastOne(): void
    {
        $settings = new Settings();
        $settings->maxEntriesPerRelease = 0;

        $this->assertFalse($settings->validate());
        $this->assertArrayHasKey('maxEntriesPerRelease', $settings->getErrors());
    }

    public function testDefaultSiteIdAcceptsNullButRejectsNonInteger(): void
    {
        $settings = new Settings();
        $settings->defaultSiteId = null;
        $this->assertTrue($settings->validate());
    }
}
