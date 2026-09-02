<?php

namespace typedef\uniquevaluefield\web\assets\uniquevaluefield;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Unique Value Field asset bundle
 */
class UniqueValueFieldAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [CpAsset::class];
    public $js = ['scripts.js'];
    public $css = ['styles.css'];
}
