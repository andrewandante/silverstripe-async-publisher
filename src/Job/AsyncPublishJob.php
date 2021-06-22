<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Job;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

class AsyncPublishJob extends AbstractQueuedJob implements QueuedJob
{
    use Injectable;

    public function __construct(?DataObject $object = null, ?string $toStage = null)
    {
        if ($object) {
            $this->objectID = $object->ID;
            $this->objectClass = ClassInfo::class_name($object);
            $this->objectTitle = $object->Title ?? 'unknown';
        }

        $this->toStage = $toStage ?? Versioned::LIVE;
    }

    public function getJobType()
    {
        $this->totalSteps = 1;
        return QueuedJob::QUEUED;
    }

    public function getSignature()
    {
        return md5(sprintf("%s-%s", $this->objectID, $this->objectClass));
    }

    /**
     * @inheritDoc
     */
    public function getTitle()
    {
        return sprintf("Async Publish %s", $this->objectTitle);
    }

    /**
     * @inheritDoc
     */
    public function process()
    {
        $object = DataObject::get($this->objectClass)->byID($this->objectID);
        if ($object && $object->hasExtension(AsyncPublishExtension::class)) {
            $object->doPublishRecursive();
        }

        $this->isComplete = true;
        return;
    }

}
