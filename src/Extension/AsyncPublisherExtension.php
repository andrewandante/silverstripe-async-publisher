<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Extension;

use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncDoSaveJob;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncPublishJob;
use AndrewAndante\SilverStripe\AsyncPublisher\Service\AsyncPublisherService;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
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
        $isWriting = $this->pendingAsyncJobsExist([AsyncDoSaveJob::class]);
        $isPublishing = $this->pendingAsyncJobsExist([AsyncPublishJob::class]);
        if ($isWriting || $isPublishing) {
            $verb = $isPublishing ? 'publishing' : 'writing';
            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'PendingJobsHeader',
                '<div class="alert alert-warning">' . _t(
                    __CLASS__ . '.PENDING_JOBS_WARNING',
                    sprintf(
                        "This is currently queued for %s - some fields may show stale values. <br />
                                Please try refreshing the page in a minute or so for editing",
                        $verb
                    )
                )
                . '</div>'
            ), 'Title');
        }
    }

    /**
     * If enabled, switches out the Save and Publish buttons for Queue Save and Queue Publish
     * Pushes the originals to the More Options (...) menu and rebrands with Force prefixes
     *
     * @param FieldList $actions
     */
    public function updateCMSActions(FieldList $actions)
    {
        /** @var CompositeField $majorActions */
        $majorActions = $actions->fieldByName('MajorActions');
        $moreOptions = $actions->fieldByName('ActionMenus.MoreOptions');
        $canSave = $majorActions->fieldByName('action_save') !== null;
        $canPublish = $majorActions->fieldByName('action_publish') !== null;
        $noChangesClasses = 'btn-outline-primary font-icon-tick';

        /**
         * If enabled and preferAsync === true, we replace the default CMS buttons with Queue <action> buttons
         * and add Force options to the additional actions menu as a fallback
         *
         * If preferAsync === false, we replace the buttons with the Force actions but keep the text the same,
         * and add the Queue options to the addtional actions menu
         */
        if ($this->preferAsync()) {
            if ($canSave) {
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

            if ($canPublish) {
                $forcePublish = FormAction::create(
                    'force_publish',
                    _t(__CLASS__ . '.BUTTONFORCESAVEPUBLISH', 'Force Publish')
                )
                    ->setAttribute('data-text-alternate', _t(__CLASS__ . '.BUTTONFORCESAVEPUBLISH', 'Force Publish'));
                $moreOptions->push($forcePublish);
                $majorActions->removeByName('action_publish');
                $majorActions->push(
                    FormAction::create('async_publish', _t(__CLASS__ . '.BUTTONASYNCPUBLISHED', 'Published'))
                        ->addExtraClass($noChangesClasses)
                        ->setAttribute('data-btn-alternate-add', 'btn-primary font-icon-rocket')
                        ->setAttribute('data-btn-alternate-remove', $noChangesClasses)
                        ->setUseButtonTag(true)
                        ->setAttribute(
                            'data-text-alternate',
                            _t(__CLASS__ . '.BUTTONASYNCSAVEPUBLISH', 'Queue Publish')
                        )
                );
            }
        } else {
            if ($canSave) {
                $queueSave = FormAction::create('async_save', _t(__CLASS__ . '.BUTTONASYNCSAVE', 'Queue Save'))
                    ->setAttribute('data-text-alternate', _t(__CLASS__ . '.BUTTONASYNCSAVE', 'Queue Save'));
                $moreOptions->push($queueSave);
                $majorActions->removeByName('action_save');
                $majorActions->push(
                    FormAction::create('force_save', _t(__CLASS__ . '.BUTTONASYNCSAVED', 'Saved'))
                        ->addExtraClass($noChangesClasses)
                        ->setAttribute('data-btn-alternate-add', 'btn-primary font-icon-save')
                        ->setAttribute('data-btn-alternate-remove', $noChangesClasses)
                        ->setUseButtonTag(true)
                        ->setAttribute('data-text-alternate', _t(__CLASS__ . '.BUTTONSAVE', 'Save'))
                );
            };

            if ($canPublish) {
                $queuePublish = FormAction::create(
                    'async_publish',
                    _t(__CLASS__ . '.BUTTONASYNCSAVEPUBLISH', 'Queue Publish')
                )
                    ->setAttribute('data-text-alternate', _t(__CLASS__ . '.BUTTONASYNCSAVEPUBLISH', 'Queue Publish'));
                $moreOptions->push($queuePublish);
                $majorActions->removeByName('action_publish');
                $majorActions->push(
                    FormAction::create('force_publish', _t(__CLASS__ . '.BUTTONPUBLISHED', 'Published'))
                        ->addExtraClass($noChangesClasses)
                        ->setAttribute('data-btn-alternate-add', 'btn-primary font-icon-rocket')
                        ->setAttribute('data-btn-alternate-remove', $noChangesClasses)
                        ->setUseButtonTag(true)
                        ->setAttribute(
                            'data-text-alternate',
                            _t(__CLASS__ . '.BUTTONSAVEPUBLISH', 'Publish')
                        )
                );
            }
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
        if (!Director::is_cli() && $this->pendingAsyncJobsExist()) {
            return false;
        }

        return null;
    }

    public function canPublish($member = null): ?bool
    {
        if (!Director::is_cli() && $this->pendingAsyncJobsExist()) {
            return false;
        }

        return null;
    }

    /**
     * @param string[] $classes
     * @return bool
     */
    public function pendingAsyncJobsExist(array $classes = [AsyncDoSaveJob::class, AsyncPublishJob::class]): bool
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

    /**
     * Allows the parent object to define a public function shouldPreferAsync() with
     * more granular control over whether or not to use the queue.
     *
     * @return bool
     */
    public function preferAsync()
    {
        if (ClassInfo::hasMethod($this->owner, 'shouldPreferAsync')) {
            return $this->owner->shouldPreferAsync();
        }

        return true;
    }
}
