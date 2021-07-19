<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Job;

use AndrewAndante\SilverStripe\AsyncPublisher\Service\AsyncPublisherService;
use SilverStripe\Control\Controller;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

class AsyncDoSaveJob extends AbstractQueuedJob implements QueuedJob
{
    use Injectable;

    public function __construct(
        ?array $data = [],
        ?Form $form = null,
        ?Controller $controller = null,
        ?DataObject $record = null
    ) {
        $this->signature = $this->randomSignature();
        $this->objectTitle = $record->Title ?? 'unknown';
        $this->formData = $data;
        $this->fieldsMap = null;
        $this->record = $record;
        $this->controllerClass = get_class($controller);
        // We need to deconstruct the form so we can rebuild it later
        // as its not serialisable and thus can't be stored as JobData
        if ($data && $form && $record) {
            $formDataFields = $form->Fields()->dataFields();
            $fieldsMap = [];
            foreach ($formDataFields as $field) {
                $fieldsMap[] = [
                    'className' => get_class($field),
                    'fieldName' => $field->getName(),
                    'value' => $field->Value(),
                    'title' => $field->Title,
                    // This is a catch for TreeDropdownField variants that need a source class
                    'source' => ClassInfo::hasMethod($field, 'getSourceObject') ? $field->getSourceObject() : null,
                ];
            }
            $this->fieldsMap = $fieldsMap;
            $this->signature = $record->generateSignature();
        }
    }

    public function getJobType()
    {
        $this->totalSteps = 1;
        return QueuedJob::QUEUED;
    }

    public function getSignature()
    {
        return $this->signature;
    }

    /**
     * @inheritDoc
     */
    public function getTitle()
    {
        return sprintf("Async Save %s", $this->objectTitle);
    }

    /**
     * @inheritDoc
     */
    public function process()
    {
        $controller = new $this->controllerClass();
        $data = $this->formData;
        $form = Form::create();
        foreach ($this->fieldsMap as $formFieldData) {
            if ($formFieldData['source'] !== null) {
                $field = $formFieldData['className']::create(
                    $formFieldData['fieldName'],
                    $formFieldData['title'],
                    $formFieldData['source']
                );
            } else {
                $field = $formFieldData['className']::create($formFieldData['fieldName'], $formFieldData['title']);
            }
            $field->setValue($formFieldData['value']);
            $form->Fields()->add($field);
        }
        $form = $form->loadDataFrom($data, Form::MERGE_AS_SUBMITTED_VALUE);
        $message = $controller->doSave($data, $form);
        $this->addMessage($message);
        $this->isComplete = true;
    }

}
