<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Job;

use AndrewAndante\SilverStripe\AsyncPublisher\Service\AsyncPublisherService;
use SilverStripe\Control\Controller;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

class AsyncDoSaveJob extends AbstractQueuedJob implements QueuedJob
{
    private static $dependencies = [
        'asyncPublisherService' => AsyncPublisherService::class,
    ];

    /**
     * @var AsyncPublisherService
     */
    protected $asyncPublisherService;

    public function __construct(?array $data = [], ?Form $form = null, ?Controller $controller = null, ?DataObject $record = null)
    {
        $this->signature = $this->randomSignature();
        $this->formData = $data;
        $this->record = $record;
        $this->controllerClass = get_class($controller);
        if ($data && $form && $record) {
            $this->asyncPublisherService->cacheFormSubmission($data, $form);
            $this->signature = $this->asyncPublisherService->generateSignature($data, $form);
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
        return 'Async doSave job';
    }

    /**
     * @inheritDoc
     */
    public function process()
    {
        /** @var Controller $controller */
        $controller = new $this->controllerClass;
        $controller->doSave($this->data, $this->asyncPublisherService->getFormSubmissionBySignature($this->signature));
        $this->isComplete = true;
    }

}
