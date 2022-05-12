<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Job;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\FormFieldExtension;
use SilverStripe\Control\Controller;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_SaveHandler;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\ORM\DataObject;
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
            $fieldsMap = [];
            $formDataFields = $form->Fields()->dataFields();
            foreach ($formDataFields as $field) {
                $fieldsMap[] = $field->toArrayForAsyncPublisher();
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
            $createArgs = [];
            foreach ($formFieldData['createArgs'] as $createArgKey) {
                $createArgs[] = $formFieldData[$createArgKey];
            }
            $field = Injector::inst()
                ->createWithArgs($formFieldData['className'], $createArgs)
                ->hydrateFromAsyncPublisherData($formFieldData);
            $field->setForm($form);
            $form->Fields()->add($field);
        }
        $form = $form->loadDataFrom($data, Form::MERGE_AS_SUBMITTED_VALUE);
        $message = $controller->doSave($data, $form);
        $this->addMessage($message);
        $this->isComplete = true;
    }
}
