<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Job;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\NullHTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;

class AsyncSave extends AbstractQueuedJob
{

    /**
     * @var int
     */
    protected $totalSteps = 0;

    /**
     * Create job
     * Arguments are optional (when they're really not) because of the way Queued Jobs rehydrates saved jobs from their
     * descriptors (job data is not set via constructor in this case).
     *
     * @see QueuedJobService::initialiseJob
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
            $this->controllerClass = $controller::class;

            if ($controller->hasMethod('asyncStoreState')) {
                $this->controllerState = $controller->asyncStoreState();
            }

            // Store the member who queued the job so permission checks pass
            // when the job runs in a context with no logged-in user.
            $member = Security::getCurrentUser();

            if ($member) {
                $this->memberID = $member->ID;
            }
        }

        $this->formName = $formName;
        $this->submission = $submission;
        $this->andPublish = isset($submission['publish']);
        // Set job data directly as there can only be one job per record
        // Job data (use to calculate the default signature) is irrelevant to whether a job already exists.
        $this->signature = $jobSignature;
    }

    public function getTitle(): string
    {
        return _t(
            self::class . '.TITLE',
            'Async write{publish} "{title}" ({class} - {ID})',
            [
                'publish' => $this->andPublish ? _t(self::class . '.AND_PUBLISH', ' and publish') : '',
                'title' => $this->submission['Title'],
                'class' => $this->submission['ClassName'],
                'ID' => $this->submission['ID'] ? '#' . $this->submission['ID'] : _t(self::class . '.NEW', 'new!'),
            ]
        );
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function process(): void
    {
        // Restore the member who queued the job so that CMS permission checks
        // (e.g. canView() on a draft-only record) pass during job processing.
        $previousUser = Security::getCurrentUser();

        if ($this->memberID) {
            $member = Member::get()->byID($this->memberID);

            if ($member) {
                Security::setCurrentUser($member);
            }
        }

        $controller = Injector::inst()->create($this->controllerClass);

        if ($controller->hasMethod('asyncRestoreState')) {
            $controller->asyncRestoreState($this->controllerState);
        }

        // A fresh controller has NullHTTPRequest by default — check for that, not null.
        $pushed = $controller->getRequest() instanceof NullHTTPRequest
            ? $this->pushControllerWithRequest($controller)
            : null;

        try {
            $form = $controller->{$this->formName}();
            $form->loadDataFrom($this->submission);

            $record = $controller->asyncGetRecordAndAssertPermissions($this->submission);

            // TODO Coupling to SiteTree
            $record->HasBrokenLink = 0;
            $record->HasBrokenFile = 0;

            if (!$record->ObsoleteClassName) {
                $record->writeWithoutVersion();
            }

            if (isset($this->submission['ClassName']) && $this->submission['ClassName'] !== $record->ClassName) {
                $record = $record->newClassInstance($this->submission['ClassName']);
            }

            $form->saveInto($record);
            $record->write();

            if ($this->andPublish) {
                // publish immediately - no point in queuing a second job when we're already executing asynchronously
                $record->doPublishRecursive();
                $message = _t(
                    self::class . '.PUBLISHED',
                    "Saved and published '{title}' from queue successfully.",
                    ['title' => $record->Title]
                );
            } else {
                $message = _t(
                    self::class . '.SAVED',
                    "Saved '{title}' from queue successfully.",
                    ['title' => $record->Title]
                );
            }

            $this->addMessage($message);
        } finally {
            $pushed?->popCurrent();
            Security::setCurrentUser($previousUser);
        }

        $this->isComplete = true;
    }

    /**
     * Assigns a real request to a controller with NullHTTPRequest, pushes it onto the controller
     * stack, and returns it so the caller can pop it in a finally block.
     *
     * @param Controller $controller A freshly created controller with NullHTTPRequest.
     * @return Controller The same controller, now pushed onto the stack.
     */
    private function pushControllerWithRequest(Controller $controller): Controller
    {
        $request = new HTTPRequest('GET', $this->controllerState['RequestURL'] ?? '/');
        $request->setSession(new Session([]));
        $request->setRouteParams($this->controllerState['URLParams'] ?? []);

        $controller->setRequest($request);
        $controller->pushCurrent();

        return $controller;
    }

}
