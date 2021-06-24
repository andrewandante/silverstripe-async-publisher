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

    private static $dependencies = [
        'asyncPublisherService' => AsyncPublisherService::class,
    ];

    /**
     * @var AsyncPublisherService
     */
    protected $asyncPublisherService;

    public function __construct(
        ?array $data = [],
        ?Form $form = null,
        ?Controller $controller = null,
        ?DataObject $record = null
    ) {
        $this->asyncPublisherService = Injector::inst()->get(AsyncPublisherService::class);
        $this->signature = $this->randomSignature();
        $this->objectTitle = $record->Title ?? 'unknown';
        $this->formData = $data;
        $this->record = $record;
        $this->controllerClass = get_class($controller);
        if ($data && $form && $record) {
            $this->asyncPublisherService->cacheFormSubmission($record, $form);
            $this->signature = AsyncPublisherService::generateSignature($record);
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
        $form = $this->asyncPublisherService->getFormSubmissionBySignature($this->signature);
        $data = $this->formData;
        $message = $controller->doSave($data, $form);
        $this->addMessage($message);
        $this->isComplete = true;
    }

}
