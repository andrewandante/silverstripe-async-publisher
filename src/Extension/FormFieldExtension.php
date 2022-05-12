<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Extension;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_SaveHandler;

class FormFieldExtension extends Extension
{
    public function toArrayForAsyncPublisher()
    {
        $field = $this->getOwner();
        $data = [
            'createArgs' => [
                'fieldName',
                'title',
            ],
            'className' => get_class($field),
            'fieldName' => $field->getName(),
            'value' => $field->Value(),
            'title' => $field->Title,
        ];

        $field->invokeWithExtensions('updateArrayForAsyncPublisher', $data);

        return $data;
    }

    public function hydrateFromAsyncPublisherData(array $data)
    {
        $field = $this->getOwner();
        $field->setValue($data['value']);

        $field->invokeWithExtensions('updateFieldHydratedFromAsyncPublisherData', $field, $data);

        return $field;
    }
}
