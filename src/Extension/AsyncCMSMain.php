<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Extension;

use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncDoSaveJob;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class AsyncCMSMain extends Extension
{
    private static $allowed_actions = [
        'async_publish',
    ];

    public function async_publish($data, $form)
    {
        $data['publish'] = 1;
        return $this->save($data, $form);
    }
    /**
     * Save and Publish page handler
     * Need to catch this before it hits the loop or we'll be in trouble
     *
     * @param array $data
     * @param Form $form
     * @return HTTPResponse
     * @throws HTTPResponse_Exception
     */
    public function save($data, $form)
    {
        $className = $this->owner->config()->get('tree_class');

        // Existing or new record?
        $id = $data['ID'];
        if (substr($id, 0, 3) != 'new') {
            /** @var SiteTree $record */
            $record = DataObject::get_by_id($className, $id);
            // Check edit permissions
            if ($record && !$record->canEdit()) {
                return Security::permissionFailure($this);
            }
            if (!$record || !$record->ID) {
                throw new HTTPResponse_Exception("Bad record ID #$id", 404);
            }
        } else {
            if (!$className::singleton()->canCreate()) {
                return Security::permissionFailure($this);
            }
            $record = $this->owner->getNewItem($id, false);
        }

        $job = AsyncDoSaveJob::create($data, $form, Controller::curr(), $record);
        QueuedJobService::singleton()->queueJob($job);
        if (!empty($data['publish'])) {
            $message = _t(
                __CLASS__ . '.QUEUED_PUBLISHED',
                "Queued for publish successfully."
            );
        } else {
            $message = _t(
                __CLASS__ . '.QUEUED_SAVED',
                "Queued for save successfully."
            );
        }
        $this->owner->getResponse()->addHeader('X-Status', rawurlencode($message));
        return $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
    }

    public function doSave($data, $form)
    {
        $className = $this->owner->config()->get('tree_class');

        // Existing or new record?
        $id = $data['ID'];
        if (substr($id, 0, 3) != 'new') {
            /** @var SiteTree $record */
            $record = DataObject::get_by_id($className, $id);
            // Check edit permissions
            if ($record && !$record->canEdit()) {
                return Security::permissionFailure($this);
            }
            if (!$record || !$record->ID) {
                throw new HTTPResponse_Exception("Bad record ID #$id", 404);
            }
        } else {
            if (!$className::singleton()->canCreate()) {
                return Security::permissionFailure($this);
            }
            $record = $this->owner->getNewItem($id, false);
        }

        // Check publishing permissions
        $doPublish = !empty($data['publish']);
        if ($record && $doPublish && !$record->canPublish()) {
            return Security::permissionFailure($this);
        }

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

        // If the 'Publish' button was clicked, also publish the page
        if ($doPublish) {
            $record->publishRecursive();
            $message = _t(
                __CLASS__ . '.PUBLISHED',
                "Published '{title}' successfully.",
                ['title' => $record->Title]
            );
        } else {
            $message = _t(
                __CLASS__ . '.SAVED',
                "Saved '{title}' successfully.",
                ['title' => $record->Title]
            );
        }

        return $message;
    }
}
