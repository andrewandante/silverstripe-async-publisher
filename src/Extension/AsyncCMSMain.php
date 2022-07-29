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
        $response->addHeader('X-ControllerURL', $record->CMSEditLink());

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
        $className = $this->owner->config()->get('tree_class');

        // Existing or new record?
        $id = $data['ID'];

        if (!str_starts_with($id ?? '', 'new')) {
            /** @var SiteTree $record */
            $record = DataObject::get_by_id($className, $id);

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
            'URLParams' => $this->owner->getURLParams(),
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
