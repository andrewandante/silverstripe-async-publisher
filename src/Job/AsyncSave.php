<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Job;

use SilverStripe\ORM\DataObject;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;

class AsyncSave extends AbstractQueuedJob
{
    protected $totalSteps = 0;

    /**
     * Create job
     * Both arguments are optional (when they're really not) because of the way Queued Jobs rehydrates saved jobs
     * from their descriptors
     *
     * @see QueuedJobService::initialiseJob
     *
     * @param DataObject|null $record
     * @param boolean|null $publish
     */
    public function __construct(?DataObject $record = null, ?bool $publish = false)
    {
        $this->dataRecord = $record;
        $this->doPublish = $publish;
        if ($record) {
            $this->signature = $record->generateSignature();
        }
    }

    public function getTitle()
    {
        return _t(
            __CLASS__ . '.TITLE',
            'Async write{publish} "{title}" ({class} - {ID})',
            [
                'publish' => $this->doPublish ? _t(__CLASS__ . '.AND_PUBLISH', ' and publish') : '',
                'title' => $this->dataRecord->Title,
                'class' => $this->dataRecord->ClassName,
                'ID' => $this->dataRecord->ID ? '#' . $this->dataRecord->ID : _t(__CLASS__ . '.NEW', 'new!'),
            ]
        );
    }

    public function getSignature()
    {
        return $this->signature;
    }

    public function process()
    {
        $record = $this->dataRecord;
        $record->write();

        if ($this->doPublish) {
            $record->doPublishRecursive();
            $message = _t(
                __CLASS__ . '.PUBLISHED',
                "Saved and published '{title}' from queue successfully.",
                ['title' => $record->Title]
            );
        } else {
            $message = _t(
                __CLASS__ . '.SAVED',
                "Saved '{title}' from queue successfully.",
                ['title' => $record->Title]
            );
        }
        $this->addMessage($message);
        $this->isComplete = true;
    }
}
