<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Extension;

use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncPublish;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncSave;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Versioned\RecursivePublishable;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class AsyncPublisherExtension extends Extension
{

    public function updateCMSFields(FieldList $fields): void
    {
        $isWriting = $this->pendingAsyncJobsExist([AsyncSave::class]);
        $isPublishing = $this->pendingAsyncJobsExist([AsyncPublish::class]);

        if (!$isWriting && !$isPublishing) {
            return;
        }

        $verb = $isWriting ? _t(self::class . '.WRITING', 'writing') : _t(self::class . '.PUBLISHING', 'publishing');
        $historicData = $isWriting ? _t(self::class . '.HISTORIC_DATA', ' - fields show historic content') : '';
        $queuedMessage = _t(
            self::class . '.PENDING_JOBS_WARNING',
            'This {ObjectType} is currently queued for {ActionType}{HistoricData}.
            Please try refreshing the page in a minute or so for editing',
            [
                'ObjectType' => $this->owner->i18n_singular_name(),
                'ActionType' => $verb,
                'HistoricData' => $historicData,
            ]
        );
        $fields->addFieldToTab(
            'Root.Main',
            LiteralField::create(
                'PendingJobsHeader',
                '<div class="alert alert-warning">' . nl2br($queuedMessage ?? '') . '</div>'
            ),
            'Title'
        );
    }

    /**
     * If enabled, switches out the Save and Publish buttons for Queue Save and Queue Publish
     * Pushes the originals to the More Options (`...`) menu and rebrands as 'immediate' options
     *
     * @param FieldList $actions
     */
    public function updateCMSActions(FieldList $actions): void
    {
        /** @var CompositeField $majorActions */
        $majorActions = $actions->fieldByName('MajorActions');
        $moreOptions = $actions->fieldByName('ActionMenus.MoreOptions');
        $saveButton = $majorActions->fieldByName('action_save');
        $publishButton = $majorActions->fieldByName('action_publish');
        // canSave() & canPublish() have run as part of the normal action button additions
        // it would also waste resources to check again
        $canSave = $saveButton !== null;
        $canPublish = $publishButton !== null;
        $noChangesClasses = 'btn-outline-primary font-icon-tick';
        $changesClassesWithoutIconName = 'btn-primary font-icon-';

        if (!$canSave && !$canPublish) {
            return;
        }

        $queueSave = FormAction::create('asyncSave', _t(self::class . '.BUTTON_QUEUE_SAVED', 'Saved'))
            ->addExtraClass($noChangesClasses)
            ->setAttribute('data-btn-alternate-add', $changesClassesWithoutIconName . 'save')
            ->setAttribute('data-btn-alternate-remove', $noChangesClasses)
            ->setUseButtonTag(true)
            ->setAttribute('data-text-alternate', _t(self::class . '.BUTTON_QUEUE_SAVE', 'Queue Save'));

        $queuePublish = FormAction::create('asyncPublish', _t(self::class . '.BUTTON_QUEUE_PUBLISHED', 'Published'))
            ->addExtraClass($noChangesClasses)
            ->setAttribute('data-btn-alternate-add', $changesClassesWithoutIconName . 'rocket')
            ->setAttribute('data-btn-alternate-remove', $noChangesClasses)
            ->setUseButtonTag(true)
            ->setAttribute('data-text-alternate', _t(self::class . '.BUTTON_QUEUE_PUBLISH', 'Queue Publish'));

        /**
         * If enabled and preferAsync === true, we replace the default CMS buttons with Queue <action> buttons
         * and add move the 'immediate' options to the additional actions menu as a fallback
         *
         * If preferAsync === false, we add the Queue options to the addtional actions menu
         */
        if ($this->preferAsync()) {
            if ($canSave) {
                $majorActions->removeByName('action_save');
                $moreOptions->push($saveButton);
                $majorActions->push($queueSave);
                $saveButton->setTitle(_t(self::class . '.BUTTON_IMMEDIATE_SAVED', 'Saved (immediate)'))
                    ->setAttribute(
                        'data-text-alternate',
                        _t(self::class . '.BUTTON_IMMEDIATE_SAVE', 'Save immediately')
                    );
            }

            if ($canPublish) {
                $majorActions->removeByName('action_publish');
                $moreOptions->push($publishButton);
                $majorActions->push($queuePublish);
                $publishButton->setTitle(_t(self::class . '.BUTTON_IMMEDIATE_PUBLISHED', 'Published (immediate)'))
                    ->setAttribute(
                        'data-text-alternate',
                        _t(self::class . '.BUTTON_IMMEDIATE_PUBLISH', 'Publish immediately')
                    );
            }
        } else {
            if ($canSave) {
                $moreOptions->push($queueSave);
            }

            if ($canPublish) {
                $moreOptions->push($queuePublish);
            }
        }
    }

    public function publishRecursive(): bool
    {
        $publishJob = AsyncPublish::create($this->owner, Versioned::LIVE);
        $jobID = QueuedJobService::singleton()->queueJob($publishJob);

        return $jobID > 0;
    }

    public function doPublishRecursive(): bool
    {
        $recursivePublishable = $this->owner->getExtensionInstance(RecursivePublishable::class);
        $recursivePublishable->setOwner($this->owner);
        $result = $recursivePublishable->publishRecursive();
        $recursivePublishable->clearOwner();

        return $result;
    }

    /**
     * @param null $member
     * @return false|null
     */
    public function canEdit($member = null): ?bool
    {
        if (!Director::is_cli() && $this->pendingAsyncJobsExist()) {
            return false;
        }

        return null;
    }

    /**
     * @param null $member
     * @return bool|null
     */
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
    public function pendingAsyncJobsExist(array $classes = [AsyncSave::class, AsyncPublish::class]): bool
    {
        return QueuedJobDescriptor::get()->filter([
            'Implementation' => $classes,
            'Signature' => $this->generateSignature(),
            'JobStatus' => [
                QueuedJob::STATUS_NEW,
                QueuedJob::STATUS_INIT,
                QueuedJob::STATUS_WAIT,
            ],
        ])->exists();
    }

    /**
     * Allows the parent object to define a public function shouldPreferAsync() with
     * more granular control over whether or not to use the queue.
     *
     * @return bool
     */
    public function preferAsync(): bool
    {
        if ($this->owner->hasMethod('shouldPreferAsync')) {
            return $this->owner->shouldPreferAsync();
        }

        return true;
    }

    /**
     * Generate a signature unique but consistent to this record
     *
     * Ultimately used by QueuedJobs, where we use it in turn above to find if there are existing async save/publish
     * jobs waiting to be processed ({@see self::pendingAsyncJobsExist()})
     *
     * Queued jobs typically generate signatures based on the data of the job, where as the jobs relating to async
     * save or publish relate to the record specifically - the data is of no consequence. This invariance makes
     * searching for exisitng jobs queued for this record much easier to find - this way we avoid "race conditions"
     * where data can be updated while a job to save is queued, leaving the content in an inconsistent state from
     * the authors perspective.
     *
     * @return string
     */
    public function generateSignature(): string
    {
        return md5(sprintf('%s-%s', $this->owner->ID, $this->owner->ClassName));
    }

}
