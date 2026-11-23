<?php

declare(strict_types=1);

use JustinHoltWeb\Peek\Tests\TestCase;

/*
 | Every suite runs on a real Craft 6 install with Peek installed, on in-memory SQLite
 | (CraftCms\Cms\Plugin\Testing\PluginTestCase), so nothing touches a shared database.
 */
uses(TestCase::class)->in('Unit', 'Feature');
