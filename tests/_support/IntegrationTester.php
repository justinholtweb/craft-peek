<?php

declare(strict_types=1);

namespace justinholtweb\peek\tests\Support;

/**
 * Actor for the integration suite, which runs against a real (throwaway) Craft
 * install with the Peek plugin loaded.
 *
 * @SuppressWarnings(PHPMD)
 */
class IntegrationTester extends \Codeception\Actor
{
    use _generated\IntegrationTesterActions;
}
