<?php

namespace justinholtweb\peek\web\assets\diff;

use craft\web\AssetBundle;
use justinholtweb\peek\web\assets\cp\CpAsset;

class DiffAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['css/diff.css'];
        $this->js = ['js/diff.js'];

        parent::init();
    }
}
