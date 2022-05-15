<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Extension;

use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncSave;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
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
    private static $allowed_actions = [
        'asyncSave',
        'asyncPublish',
    ];

    public function asyncPublish($data, $form)
    {
        $data['publish'] = 1;
        return $this->asyncSave($data, $form);
    }

    public function asyncSave($data, $form)
    {
        $record = $this->saveWithoutWrite($data, $form);
        $publishingToo = isset($data['publish']);

        $injector = Injector::inst();
        $job = $injector->create(AsyncSave::class, $record, $publishingToo);
        $queueService = $injector->get(QueuedJobService::class);
        $queueService->queueJob($job);

        if ($publishingToo) {
            $message = _t(
                __CLASS__ . '.QUEUED_FOR_PUBLISHING',
                "Queued '{title}' for saving and publishing successfully.",
                ['title' => $record->Title]
            );
        } else {
            $message = _t(
                __CLASS__ . '.QUEUED_FOR_SAVING',
                "Queued '{title}' for saving successfully.",
                ['title' => $record->Title]
            );
        }

        $this->owner->getResponse()->addHeader('X-Status', rawurlencode($message));
        $response = $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
        $response->addHeader('X-Reload', true);
        $response->addHeader('X-ControllerURL', $record->CMSEditLink());
        return $response;
    }

    /**
     * Copied and pasted straight out of {@see CMSMain::save} (from the vendor) excluding the lines that write
     * and omitting response status setting, instead returning the record (new or existing).
     *
     * @param array $data
     * @param Form $form
     * @return DataObject the object saved into
     */
    protected function saveWithoutWrite($data, $form)
    {
        $className = $this->owner->config()->get('tree_class');

        // Existing or new record?
        $id = $data['ID'];
        if (substr($id, 0, 3) != 'new') {
            /** @var SiteTree $record */
            $record = DataObject::get_by_id($className, $id);
            // Check edit permissions
            if ($record && !$record->canEdit()) {
                return Security::permissionFailure($this->owner);
            }
            if (!$record || !$record->ID) {
                throw new HTTPResponse_Exception("Bad record ID #$id", 404);
            }
        } else {
            if (!$className::singleton()->canCreate()) {
                return Security::permissionFailure($this->owner);
            }
            $record = $this->owner->getNewItem($id, false);
        }

        // Check publishing permissions
        $doPublish = !empty($data['publish']);
        if ($record && $doPublish && !$record->canPublish()) {
            return Security::permissionFailure($this->owner);
        }

        // TODO Coupling to SiteTree
        $record->HasBrokenLink = 0;
        $record->HasBrokenFile = 0;

        // Update the class instance if necessary
        if (isset($data['ClassName']) && $data['ClassName'] != $record->ClassName) {
            // Replace $record with a new instance of the new class
            $newClassName = $data['ClassName'];
            $record = $record->newClassInstance($newClassName);
        }

        // save form data into record
        $form->saveInto($record);

        return $record;
    }
}
