<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Job;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;

class AsyncSave extends AbstractQueuedJob
{
    protected $totalSteps = 0;

    /**
     * Create job
     * Arguments are optional (when they're really not) because of the way Queued Jobs rehydrates saved jobs from their
     * descriptors (job data is not set via constructor in this case).
     *
     * @see QueuedJobService::initialiseJob
     *
     * @param Controller|null $controller
     * @param string|null $formName
     * @param array|null $submission
     * @param string|null $jobSignature
     */
    public function __construct(
        ?Controller $controller = null,
        ?string $formName = null,
        ?array $submission = null,
        ?string $jobSignature = null
    ) {
        if ($controller) {
            $this->controllerClass = get_class($controller);
            if ($controller->hasMethod('asyncStoreState')) {
                $this->controllerState = $controller->asyncStoreState();
            }
        }
        $this->formName = $formName;
        $this->submission = $submission;
        $this->andPublish = isset($submission['publish']);
        // Set job data directly as there can only be one job per record
        // Job data (use to calculate the default signature) is irrelevant to whether a job already exists.
        $this->signature = $jobSignature;
    }

    public function getTitle()
    {
        return _t(
            __CLASS__ . '.TITLE',
            'Async write{publish} "{title}" ({class} - {ID})',
            [
                'publish' => $this->andPublish ? _t(__CLASS__ . '.AND_PUBLISH', ' and publish') : '',
                'title' => $this->submission['Title'],
                'class' => $this->submission['ClassName'],
                'ID' => $this->submission['ID'] ? '#' . $this->submission['ID'] : _t(__CLASS__ . '.NEW', 'new!'),
            ]
        );
    }

    public function getSignature()
    {
        return $this->signature;
    }

    public function process()
    {
        $controller = Injector::inst()->create($this->controllerClass);
        if ($controller->hasMethod('asyncRestoreState')) {
            $controller->asyncRestoreState($this->controllerState);
        }
        $form = $controller->{$this->formName}();
        $form->loadDataFrom($this->submission);

        $record = $controller->asyncGetRecordAndAssertPermissions($this->submission);

        // START code copied from CMSMain::save

        // TODO Coupling to SiteTree
        $record->HasBrokenLink = 0;
        $record->HasBrokenFile = 0;

        if (!$record->ObsoleteClassName) {
            $record->writeWithoutVersion();
        }

        // Update the class instance if necessary
        if (isset($data['ClassName']) && $data['ClassName'] != $record->ClassName) {
            // Replace $record with a new instance of the new class
            $newClassName = $data['ClassName'];
            $record = $record->newClassInstance($newClassName);
        }

        // save form data into record
        $form->saveInto($record);
        $record->write();

        // END code copied from CMSMain::save

        // Errors will have been thrown before we reach this point; assume success if we're here (like CMSMain::save)
        if ($this->andPublish) {
            // publish immediately - no point in queuing a second job when we're already executing asynchronously
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
