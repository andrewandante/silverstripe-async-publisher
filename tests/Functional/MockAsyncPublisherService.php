<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Functional;

use AndrewAndante\SilverStripe\AsyncPublisher\Service\AsyncPublisherService;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Forms\Form;

class MockAsyncPublisherService extends AsyncPublisherService implements TestOnly
{
    /**
     * @var array
     */
    private $formDataCacheAsArray = [];

    // Mock the method without using an actual cache
    public function cacheFormSubmission($record, Form $form)
    {
        $signature = self::generateSignature($record);
        $this->formDataCacheAsArray[$signature]['data'] = $form->getData();
        $formDataFields = $form->Fields()->dataFields();
        $fieldsMap = [];
        foreach ($formDataFields as $field) {
            $fieldsMap[] = [
                'className' => get_class($field),
                'fieldName' => $field->getName(),
                'value' => $field->Value(),
            ];
        }
        $this->formDataCacheAsArray[$signature]['fields'] = $fieldsMap;

        return $signature;
    }

    public function getFormSubmissionBySignature(string $signature)
    {
        $formData = $this->formDataCacheAsArray[$signature]['data'];
        $formFields = $this->formDataCacheAsArray[$signature]['fields'];
        $form = Form::create();
        foreach ($formFields as $formFieldData) {
            $field = $formFieldData['className']::create($formFieldData['fieldName']);
            $field->setValue($formFieldData['value']);
            $form->Fields()->add($field);
        }
        return $form->loadDataFrom($formData, Form::MERGE_AS_SUBMITTED_VALUE);
    }
}
