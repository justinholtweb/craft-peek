<?php

namespace justinholtweb\peek\tests\integration;

use Craft;
use justinholtweb\peek\models\Settings;
use justinholtweb\peek\Plugin;
use justinholtweb\peek\tests\Support\PeekTestCase;

/**
 * The CP nav label and icon: validation, and the precedence SVG > icon name > Peek's own icon.
 */
class NavSettingsTest extends PeekTestCase
{
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function _before(): void
    {
        parent::_before();
        $settings = Plugin::getInstance()->getSettings();
        $this->saved = ['navLabel' => $settings->navLabel, 'navIcon' => $settings->navIcon, 'navIconSvg' => $settings->navIconSvg];
    }

    protected function _after(): void
    {
        $settings = Plugin::getInstance()->getSettings();
        foreach ($this->saved as $name => $value) {
            $settings->$name = $value;
        }
        parent::_after();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function nav(array $values): array
    {
        $settings = Plugin::getInstance()->getSettings();
        foreach ($values as $name => $value) {
            $settings->$name = $value;
        }

        return Plugin::getInstance()->getCpNavItem();
    }

    public function testDefaultsAreEmptyAndValid(): void
    {
        $settings = new Settings();

        $this->assertNull($settings->navLabel);
        $this->assertNull($settings->navIcon);
        $this->assertNull($settings->navIconSvg);
        $this->assertTrue($settings->validate());
    }

    public function testLabelIsCappedAt64Characters(): void
    {
        $settings = new Settings(['navLabel' => str_repeat('a', 65)]);
        $this->assertFalse($settings->validate());
        $this->assertArrayHasKey('navLabel', $settings->getErrors());

        $settings->navLabel = str_repeat('a', 64);
        $this->assertTrue($settings->validate());
    }

    public function testIconMustBeOneOfCraftsIcons(): void
    {
        $this->assertTrue((new Settings(['navIcon' => 'eye']))->validate());

        foreach (['not-a-real-icon-name', 'Eye', '../etc/passwd', 'eye.svg'] as $bad) {
            $settings = new Settings(['navIcon' => $bad]);
            $this->assertFalse($settings->validate(), $bad);
            $this->assertArrayHasKey('navIcon', $settings->getErrors());
        }
    }

    public function testSvgMustBeASingleSvgElement(): void
    {
        $this->assertTrue((new Settings(['navIconSvg' => self::SVG]))->validate());
        $this->assertTrue((new Settings(['navIconSvg' => '<?xml version="1.0"?>' . "\n" . self::SVG]))->validate());

        foreach (['<div>no</div>', '<svg><circle/>', self::SVG . '<script>alert(1)</script>', 'hello'] as $bad) {
            $settings = new Settings(['navIconSvg' => $bad]);
            $this->assertFalse($settings->validate(), $bad);
        }

        $tooBig = new Settings(['navIconSvg' => '<svg>' . str_repeat(' ', Settings::MAX_SVG_BYTES) . '</svg>']);
        $this->assertFalse($tooBig->validate());
    }

    public function testValuesAreTrimmed(): void
    {
        $settings = new Settings(['navLabel' => '  Staging  ', 'navIcon' => ' eye ']);

        $this->assertTrue($settings->validate());
        $this->assertSame('Staging', $settings->navLabel);
        $this->assertSame('eye', $settings->navIcon);
    }

    public function testNavUsesPeeksOwnLabelAndIconByDefault(): void
    {
        $default = $this->nav(['navLabel' => null, 'navIcon' => null, 'navIconSvg' => null]);

        $this->assertSame('Peek', $default['label']);
        $this->assertSame(Plugin::getInstance()->getBasePath() . DIRECTORY_SEPARATOR . 'icon-mask.svg', $default['icon']);
        $this->assertSame('Peek', $this->nav(['navLabel' => '   '])['label'], 'A blank label is no label.');
    }

    public function testNavLabelAndIconName(): void
    {
        $nav = $this->nav(['navLabel' => 'Staging', 'navIcon' => 'eye', 'navIconSvg' => null]);

        $this->assertSame('Staging', $nav['label']);
        $this->assertSame('eye', $nav['icon']);
    }

    public function testSvgWinsOverTheIconNameAndIsSanitized(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(2)</script><circle cx="5" cy="5" r="4"/></svg>';
        $nav = $this->nav(['navIcon' => 'eye', 'navIconSvg' => $svg]);

        $this->assertStringStartsWith('<svg', $nav['icon']);
        $this->assertStringContainsString('<circle', $nav['icon']);
        $this->assertStringNotContainsString('onload', $nav['icon']);
        $this->assertStringNotContainsString('<script', $nav['icon']);
    }

    public function testAnInvalidStoredIconFallsBack(): void
    {
        // Settings can arrive from project config or config/peek.php without passing validation.
        $nav = $this->nav(['navIcon' => 'not-a-real-icon-name', 'navIconSvg' => '<div>not svg</div>']);

        $this->assertSame(Plugin::getInstance()->getBasePath() . DIRECTORY_SEPARATOR . 'icon-mask.svg', $nav['icon']);
    }

    public function testTheSettingsScreenShowsTheNavFields(): void
    {
        Craft::$app->getUser()->setIdentity(\craft\elements\User::find()->id($this->adminId())->one());
        // The CP layout publishes asset bundles; keep them in test storage, not the repo's webroot.
        $published = CRAFT_STORAGE_PATH . '/runtime/cpresources';
        is_dir($published) || mkdir($published, 0775, true);
        Craft::$app->getAssetManager()->basePath = $published;
        Craft::$app->getView()->setTemplateMode('cp');
        $html = Craft::$app->getView()->renderTemplate('peek/settings/_index', [
            'settings' => new Settings(['navLabel' => 'Staging']),
            'plugin' => Plugin::getInstance(),
        ]);

        Craft::$app->getUser()->setIdentity(null);

        foreach (['name="navLabel"', 'name="navIcon"', 'name="navIconSvg"', 'value="Staging"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }
}
