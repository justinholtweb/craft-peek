<?php

/**
 * Unit-suite bootstrap.
 *
 * Peek's unit tests exercise pure model/enum/service logic without booting a
 * full Craft application. Yii's base validators (used by Model::validate())
 * reference the global `Yii` helper class, which is not registered by the
 * Composer autoloader, so we pull it in here. This keeps validation-rule tests
 * runnable while still avoiding a database or web-app bootstrap.
 */

if (!class_exists('Yii', false)) {
    require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
}
