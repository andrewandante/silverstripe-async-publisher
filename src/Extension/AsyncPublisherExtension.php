<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Extension;

use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncDoSaveJob;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncPublishJob;
use AndrewAndante\SilverStripe\AsyncPublisher\Service\AsyncPublisherService;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Versioned\ChangeSet;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class AsyncPublisherExtension extends Extension
{
    public function updateCMSFields(FieldList $fields)
    {
        $isWriting = $this->pendingJobsExist([AsyncDoSaveJob::class]);
        $isPublishing = $this->pendingJobsExist([AsyncPublishJob::class]);
        if ($isWriting || $isPublishing) {
            $verb = $isPublishing ? 'publishing' : 'writing';
            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'PendingJobsHeader',
                '<div class="alert alert-warning">' . _t(
                    __CLASS__ . '.PENDING_JOBS_WARNING',
                    sprintf("This is currently queued for %s - some fields may show stale values. <br />Please try refreshing the page in a minute or so for editing", $verb)
                )
                . '</div>'
            ), 'Title');
        }
    }

    public function updateCMSActions(FieldList $actions)
    {
        /** @var CompositeField $majorActions */
        $majorActions = $actions->fieldByName('MajorActions');
        $moreOptions = $actions->fieldByName('ActionMenus.MoreOptions');

        $noChangesClasses = 'btn-outline-primary font-icon-tick';

        // This is a quick way to check that canEdit === true
        if ($majorActions->fieldByName('action_save') !== null) {
            $forceSave = FormAction::create('force_save', _t(__CLASS__ . '.BUTTONFORCESAVE', 'Force Save'))
                ->setAttribute('data-text-alternate', _t(__CLASS__ . '.BUTTONFORCESAVE', 'Force Save'));
            $moreOptions->push($forceSave);
            $majorActions->removeByName('action_save');
            $majorActions->push(
                FormAction::create('async_save', _t(__CLASS__ . '.BUTTONASYNCSAVED', 'Saved'))
                    ->addExtraClass($noChangesClasses)
                    ->setAttribute('data-btn-alternate-add', 'btn-primary font-icon-save')
                    ->setAttribute('data-btn-alternate-remove', $noChangesClasses)
                    ->setUseButtonTag(true)
                    ->setAttribute('data-text-alternate', _t(__CLASS__ . '.BUTTONASYNCSAVE', 'Queue Save'))
            );
        };


        // This is a quick way to check that canPublish === true
        if ($majorActions->fieldByName('action_publish') !== null) {
            $forcePublish = FormAction::create('force_publish', _t(__CLASS__ . '.BUTTONFORCESAVEPUBLISH', 'Force Publish'))
                ->setAttribute('data-text-alternate', _t(__CLASS__ . '.BUTTONFORCESAVEPUBLISH', 'Force Publish'));
            $moreOptions->push($forcePublish);
            $majorActions->removeByName('action_publish');
            $majorActions->push(
                FormAction::create('async_publish', _t(__CLASS__ . '.BUTTONASYNCPUBLISHED', 'Published'))
                    ->addExtraClass($noChangesClasses)
                    ->setAttribute('data-btn-alternate-add', 'btn-primary font-icon-rocket')
                    ->setAttribute('data-btn-alternate-remove', $noChangesClasses)
                    ->setUseButtonTag(true)
                    ->setAttribute('data-text-alternate', _t(__CLASS__ . '.BUTTONASYNCSAVEPUBLISH', 'Queue Publish'))
            );
        }
    }

    public function publishRecursive()
    {
        $publishJob = AsyncPublishJob::create($this->owner, Versioned::LIVE);
        QueuedJobService::singleton()->queueJob($publishJob);
    }

    public function doPublishRecursive()
    {
        $now = DBDatetime::now()->Rfc2822();

        return DBDatetime::withFixedNow($now, function () {
            /** @var DataObject|Versioned $owner */
            $owner = $this->owner;

            // get the last published version
            $original = null;
            if ($owner->hasExtension(Versioned::class) && $owner->isPublished()) {
                $original = Versioned::get_by_stage($owner->baseClass(), Versioned::LIVE)
                    ->byID($owner->ID);
            }

            $owner->invokeWithExtensions('onBeforePublishRecursive', $original);

            // Create a new changeset for this item and publish it
            $changeset = ChangeSet::create();
            $changeset->IsInferred = true;
            $changeset->Name = _t(
                __CLASS__ . '.INFERRED_TITLE',
                "Generated by publish of '{title}' at {created}",
                [
                    'title' => $owner->Title,
                    'created' => DBDatetime::now()->Nice()
                ]
            );

            $changeset->write();
            $changeset->addObject($owner);

            $result = $changeset->publish(true);
            if ($result) {
                $owner->invokeWithExtensions('onAfterPublishRecursive', $original);
            }

            return $result;
        });
    }

    public function canEdit($member = null)
    {
        if (!Director::is_cli() && $this->pendingJobsExist([AsyncDoSaveJob::class])) {
            return false;
        }

        return null;
    }

    public function canPublish($member = null): ?bool
    {
        if (!Director::is_cli() && $this->pendingJobsExist([AsyncDoSaveJob::class, AsyncPublishJob::class])) {
            return false;
        }

        return null;
    }

    /**
     * @param string[] $classes
     * @return bool
     */
    private function pendingJobsExist(array $classes): bool
    {
        return QueuedJobDescriptor::get()->filter([
            'Implementation' => $classes,
            'Signature' => AsyncPublisherService::generateSignature($this->owner),
            'JobStatus' => [
                QueuedJob::STATUS_NEW,
                QueuedJob::STATUS_INIT,
                QueuedJob::STATUS_WAIT,
            ]
        ])->exists();
    }
}
