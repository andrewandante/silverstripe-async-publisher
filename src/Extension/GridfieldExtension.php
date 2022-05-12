<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Extension;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_SaveHandler;
use SilverStripe\Forms\GridField\GridFieldConfig;

class GridfieldExtension extends Extension
{
    public function updateArrayForAsyncPublisher(array &$data)
    {
        /** @var GridField $field */
        $field = $this->getOwner();
        $data['list'] = $field->getList();
        foreach ($field->getConfig()->getComponents() as $component) {
            if ($component instanceof GridField_SaveHandler) {
                $data['saveHandler'] = ClassInfo::class_name($component);
                if (ClassInfo::hasMethod($component, 'getDisplayFields')) {
                    foreach ($component->getDisplayFields($field) as $name => $displayField) {
                        $data['saveHandlerDisplayFields'][$name] = $name;
                    };
                }
                break;
            }
        };
    }

    public function updateFieldHydratedFromAsyncPublisherData($field, $data)
    {
        if ($data['saveHandler'] !== null) {
            /** @var GridFieldConfig $config */
            $config = $field->getConfig();
            $handlerComponent = new $data['saveHandler']();
            if ($data['saveHandlerDisplayFields'] !== null) {
                $handlerComponent->setDisplayFields($data['saveHandlerDisplayFields']);
            }
            $config->addComponent($handlerComponent);
            $field->setConfig($config);
        }

        if ($data['list'] !== null) {
            $field->setList($data['list']);
        }
    }
}
