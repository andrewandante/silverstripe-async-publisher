<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Job;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

class AsyncPublish extends AbstractQueuedJob implements QueuedJob
{

    use Injectable;

    public function __construct(?DataObject $object = null, ?string $toStage = null)
    {
        $this->signature = $this->randomSignature();

        if ($object) {
            $this->objectID = $object->ID;
            $this->objectClass = ClassInfo::class_name($object);
            $this->objectTitle = $object->Title ?? 'unknown';
            $this->signature = $object->generateSignature();
        }

        $this->toStage = $toStage ?? Versioned::LIVE;
    }

    public function getJobType(): string
    {
        $this->totalSteps = 1;

        return QueuedJob::QUEUED;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return _t(
            self::class . '.TITLE',
            'Async publish "{title}" ({class} - #{ID})',
            [
                'title' => $this->objectTitle,
                'class' => $this->objectClass,
                'ID' => $this->objectID,
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function process()
    {
        $object = DataObject::get($this->objectClass)->byID($this->objectID);

        if (!$object || !$object->exists()) {
            $this->addMessage('Could not find object');
        } elseif (!$object->hasExtension(AsyncPublisherExtension::class)) {
            $this->addMessage('Object does not have AsyncPublisherExtension applied');
        } else {
            $object->doPublishRecursive();
            $this->addMessage(_t(
                self::class . '.PUBLISHED',
                "Published '{title}' from queue successfully.",
                ['title' => $object->Title]
            ));
        }

        $this->isComplete = true;
    }

}
