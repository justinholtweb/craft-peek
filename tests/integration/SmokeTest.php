<?php

namespace justinholtweb\peek\tests\integration;

use Craft;
use craft\test\TestCase;
use justinholtweb\peek\Plugin;

/**
 * Verifies the integration harness itself: a real Craft app is booted, the
 * plugin is installed, and its migration created the tables Peek depends on.
 */
class SmokeTest extends TestCase
{
    public function testCraftAppIsBooted(): void
    {
        $this->assertNotNull(Craft::$app);
        $this->assertNotNull(Craft::$app->getSites()->getPrimarySite());
    }

    public function testPluginIsInstalled(): void
    {
        $this->assertInstanceOf(Plugin::class, Plugin::getInstance());
    }

    public function testPeekTablesExist(): void
    {
        $tables = Craft::$app->getDb()->getSchema()->getTableNames();

        $this->assertContains('peek_releases', $tables);
        $this->assertContains('peek_release_entries', $tables);
    }
}
