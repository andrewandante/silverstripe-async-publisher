<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Extension;

use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncSave;
use Psr\Container\NotFoundExceptionInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class AsyncCMSMain extends Extension
{

    /**
     * @var string[]
     */
    private static $allowed_actions = [
        'asyncSave',
        'asyncPublish',
    ];

    public function asyncPublish(array $data, Form $form): HTTPResponse
    {
        $data['publish'] = 1;

        return $this->asyncSave($data, $form);
    }

    /**
     * perform the initial step of an asynchonous save
     *
     * Checks permissions to make the action and queues the job for processing later (asynchronously).
     *
     * @param array $data
     * @param Form $form
     * @return HTTPResponse
     * @throws HTTPResponse_Exception
     * @throws NotFoundExceptionInterface
     */
    public function asyncSave(array $data, Form $form): HTTPResponse
    {
        $publishingToo = isset($data['publish']);

        // Assert permissions here to prevent waiting for a job to fail
        $record = $this->asyncGetRecordAndAssertPermissions($data);

        if ($record instanceof HTTPResponse) {
            return $record;
        }

        // New records must be persisted before queuing so the job can find
        // the record by a real ID. write() is required — the job runs with
        // no draft reading mode and cannot create the first draft version itself.
        if ($record->ID === 0) {
            // Set Title before write so the page shows its real name in the
            // sitetree immediately, not the default "New <ClassName>" placeholder.
            if (isset($data['Title'])) {
                $record->Title = $data['Title'];
            }

            $record->write();
            $data['ID'] = $record->ID;

            // The job constructor calls asyncStoreState() right after this block,
            // so URL params must already point to the real ID before that happens.
            $urlParams = $this->owner->getURLParams();
            $urlParams['ID'] = (string) $record->ID;
            $this->owner->setURLParams($urlParams);
        }

        // Allow project extensions to validate before the job is queued.
        // Return a non-empty errors array to abort with a CMS toast message.
        $errors = [];
        $this->owner->extend('validateBeforeAsyncSave', $record, $data, $errors);

        if (!empty($errors)) {
            // 400 makes the CMS JS render the toast as an error; 200 shows green.
            $this->owner->getResponse()
                ->setStatusCode(400)
                ->addHeader('X-Status', rawurlencode(implode(' ', $errors)));
            return $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
        }

        $injector = Injector::inst();
        $job = $injector->create(
            AsyncSave::class,
            $this->owner,
            $form->getName(),
            $data,
            $record->generateSignature()
        );
        $queueService = $injector->get(QueuedJobService::class);
        $queueService->queueJob($job);

        $message = $publishingToo ? _t(
            self::class . '.QUEUED_FOR_PUBLISHING',
            "Queued '{title}' for saving and publishing successfully.",
            ['title' => $record->Title]
        ) : _t(
            self::class . '.QUEUED_FOR_SAVING',
            "Queued '{title}' for saving successfully.",
            ['title' => $record->Title]
        );

        $this->owner->getResponse()->addHeader('X-Status', rawurlencode($message));
        $response = $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
        $response->addHeader('X-Reload', true);
        $response->addHeader('X-ControllerURL', $record->getCMSEditLink());

        return $response;
    }

    /**
     * Copied and pasted straight out of {@see CMSMain::save}
     * Returns the object to be saved - is handy here (before async) and in {@see AsyncSave::process} (during async)
     *
     * @param array $data form submission data from the request
     * @return DataObject|HTTPResponse data object to be saved into, or HTTP 403 response
     * @throws HTTPResponse_Exception no such DataObject exists (HTTP 404)
     */
    public function asyncGetRecordAndAssertPermissions(array $data)
    {
        $className = $this->owner->config()->get('model_class');

        // Existing or new record?
        $id = $data['ID'];

        if (!str_starts_with($id ?? '', 'new')) {
            /** @var SiteTree $record */
            $record = DataObject::get($className)->setUseCache(true)->byID($id);

            // Check edit permissions
            if ($record && !$record->canEdit()) {
                return Security::permissionFailure($this->owner);
            }

            if (!$record || !$record->ID) {
                throw new HTTPResponse_Exception('Bad record ID #' . $id, 404);
            }
        } else {
            if (!$className::singleton()->canCreate()) {
                return Security::permissionFailure($this->owner);
            }

            $record = $this->owner->getNewItem($id, false);
        }

        // Check publishing permissions
        $doPublish = isset($data['publish']);

        if ($record && $doPublish && !$record->canPublish()) {
            return Security::permissionFailure($this->owner);
        }

        return $record;
    }

    /**
     * Some controllers use state in executing their form factory methods
     *
     * Store enough state to enable the factory method to run later without issue
     *
     * @see CMSMain::EditForm()
     * @see CMSMain::currentPageID()
     * @see self::asyncRestoreState()
     * @return array
     */
    public function asyncStoreState(): array
    {
        return [
            'URLParams'  => $this->owner->getURLParams(),
            // RequestURL is used by AsyncSave::process() to build the dummy
            // HTTPRequest that replaces NullHTTPRequest when the job runs headlessly.
            'RequestURL' => $this->owner->getRequest()->getURL(true),
        ];
    }

    /**
     * Restore enough controller state to be able to successfully recreate the form that was submitted
     * from the controllers form factory method
     *
     * @see CMSMain::EditForm()
     * @see CMSMain::currentPageID()
     * @param array $stateData
     * @return void
     */
    public function asyncRestoreState(array $stateData): void
    {
        $this->owner->setURLParams($stateData['URLParams']);
    }

}
