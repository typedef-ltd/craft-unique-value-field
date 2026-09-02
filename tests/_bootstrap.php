<?php

use craft\test\TestSetup;
use Dotenv\Dotenv;

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');

Dotenv::createUnsafeImmutable(__DIR__)->safeLoad();

define('CRAFT_ROOT_PATH', dirname(__DIR__));
define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');

TestSetup::configureCraft();
