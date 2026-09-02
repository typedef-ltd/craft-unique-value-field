<?php

namespace typedef\uniquevaluefield;

use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use typedef\uniquevaluefield\fields\UniqueValueField;
use yii\base\Event;

/**
 * Unique Value Field plugin
 *
 * @method static UniqueValueFieldPlugin getInstance()
 * @author Typedef Limited <support@typedef.co>
 * @copyright Typedef Limited
 * @license MIT
 */
class UniqueValueFieldPlugin extends Plugin
{
    public function init(): void
    {
        parent::init();
        $this->attachEventHandlers();
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = UniqueValueField::class;
            }
        );
    }
}
