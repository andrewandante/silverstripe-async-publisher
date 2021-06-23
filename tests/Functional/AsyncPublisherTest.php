<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Functional;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncDoSaveJob;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncPublishJob;
use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\FunctionalTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class AsyncPublisherTest extends FunctionalTest
{
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        SiteTree::add_extension(AsyncPublisherExtension::class);
    }

    protected static $fixture_file = 'fixtures.yml';

    public function testButtonsUpdate()
    {
        $this->logInWithPermission();
        $page = $this->objFromFixture(SiteTree::class, 'first');
        $this->get($page->CMSEditLink());
        $this->assertExactMatchBySelector(
            '#Form_EditForm_MajorActions_Holder #Form_EditForm_action_async_save span',
            ['Saved']
        );
        $this->assertExactMatchBySelector(
            '#Form_EditForm_MajorActions_Holder #Form_EditForm_action_async_publish span',
            ['Published']
        );
        $this->assertPartialHTMLMatchBySelector(
            '#ActionMenus_MoreOptions #Form_EditForm_action_force_save',
            ['<input type="submit" name="action_force_save" value="Force Save" id="Form_EditForm_action_force_save" data-text-alternate="Force Save" class="btn action"/>']
        );
        $this->assertPartialHTMLMatchBySelector(
            '#ActionMenus_MoreOptions #Form_EditForm_action_force_publish',
            ['<input type="submit" name="action_force_publish" value="Force Publish" id="Form_EditForm_action_force_publish" data-text-alternate="Force Publish" class="btn action"/>']
        );
    }

    public function testQueueSave()
    {
        $this->logInWithPermission();
        $page = $this->objFromFixture(SiteTree::class, 'first');
        $this->get($page->CMSEditLink());
        $response = $this->submitForm('Form_EditForm', 'action_async_save', [
            'Content' => 'QueueSaveContent'
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(
            1,
            QueuedJobDescriptor::get()
                ->filter(['Implementation' => AsyncDoSaveJob::class])
                ->count()
        );
        $this->assertEquals(
            0,
            QueuedJobDescriptor::get()
                ->filter(['Implementation' => AsyncPublishJob::class])
                ->count()
        );

        $this->assertFalse($page->canEdit());
        $this->assertFalse($page->canPublish());

        QueuedJobService::singleton()->runJob(
            QueuedJobDescriptor::get()->filter(['Implementation' => AsyncDoSaveJob::class])->first()
        );

        $this->assertEquals('QueueSaveContent', $page->getField('Content'));

    }
}
