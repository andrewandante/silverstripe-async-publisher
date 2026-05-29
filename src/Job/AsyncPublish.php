<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Job;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
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

            // Store the member who queued the job so permission checks pass
            // when the job runs in a context with no logged-in user.
            $member = Security::getCurrentUser();
            if ($member) {
                $this->memberID = $member->ID;
            }
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
        // Restore the member who queued the job so permission checks pass.
        if ($this->memberID) {
            $member = DataObject::get_by_id(Member::class, $this->memberID);
            if ($member) {
                Security::setCurrentUser($member);
            }
        }

        // Create a real request with session and push a CMS controller so
        // CMS extensions that call Controller::curr() work during publish.
        $controller = Injector::inst()->create(CMSMain::class);
        $request = new HTTPRequest('GET', '/admin/pages/');
        $request->setSession(new Session([]));
        $controller->setRequest($request);
        $controller->pushCurrent();

        try {
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
        } finally {
            $controller->popCurrent();
        }
    }

}
