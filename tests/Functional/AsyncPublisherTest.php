<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Functional;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncDoSaveJob;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncPublishJob;
use AndrewAndante\SilverStripe\AsyncPublisher\Service\AsyncPublisherService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class AsyncPublisherTest extends FunctionalTest
{
    protected static $required_extensions = [
        SiteTree::class => AsyncPublisherExtension::class,
    ];

    protected static $extra_dataobjects = [
        SometimesAsyncPage::class,
    ];

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        $mockAsyncPublisherService = MockAsyncPublisherService::create();
        Injector::inst()->registerService($mockAsyncPublisherService, AsyncPublisherService::class);
    }

    protected static $fixture_file = 'fixtures.yml';

    public function testButtonsUpdate()
    {
        $this->logInWithPermission();
        /** @var SiteTree|AsyncPublisherExtension $page */
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
        /** @var SiteTree|AsyncPublisherExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'first');
        $this->get($page->CMSEditLink());
        $response = $this->submitForm('Form_EditForm', 'action_async_save', [
            'Content' => 'QueueSaveContent'
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($page->pendingAsyncJobsExist([AsyncDoSaveJob::class]));
        $this->assertFalse($page->pendingAsyncJobsExist([AsyncDoPublishJob::class]));

        QueuedJobService::singleton()->runJob(
            QueuedJobDescriptor::get()->filter(['Implementation' => AsyncDoSaveJob::class])->first()->ID
        );

        $this->assertEquals(
            1,
            QueuedJobDescriptor::get()
                ->filter([
                    'Implementation' => AsyncDoSaveJob::class,
                    'JobStatus' => QueuedJob::STATUS_COMPLETE,
                    'Signature' => AsyncPublisherService::generateSignature($page),
                ])
                ->count()
        );
        $this->assertFalse($page->pendingAsyncJobsExist([AsyncDoPublishJob::class]));

        $refreshedPage = SiteTree::get()->byID($page->ID);
        $this->assertEquals('QueueSaveContent', $refreshedPage->getField('Content'));
        $this->assertFalse($refreshedPage->isPublished());
    }

    public function testQueuePublish()
    {
        $this->logInWithPermission();
        /** @var SiteTree|AsyncPublisherExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'first');
        $this->get($page->CMSEditLink());
        $response = $this->submitForm('Form_EditForm', 'action_async_publish', [
            'Content' => 'QueuePublishContent'
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($page->pendingAsyncJobsExist([AsyncDoSaveJob::class]));
        $this->assertFalse($page->pendingAsyncJobsExist([AsyncPublishJob::class]));

        QueuedJobService::singleton()->runJob(
            QueuedJobDescriptor::get()->filter(['Implementation' => AsyncDoSaveJob::class])->first()->ID
        );

        $this->assertEquals(
            1,
            QueuedJobDescriptor::get()
                ->filter([
                    'Implementation' => AsyncDoSaveJob::class,
                    'JobStatus' => QueuedJob::STATUS_COMPLETE,
                    'Signature' => AsyncPublisherService::generateSignature($page),
                ])
                ->count()
        );
        $this->assertTrue($page->pendingAsyncJobsExist([AsyncPublishJob::class]));

        $refreshedPage = SiteTree::get()->byID($page->ID);
        $this->assertFalse($refreshedPage->isPublished());
        $this->assertEquals('QueuePublishContent', $refreshedPage->getField('Content'));


        QueuedJobService::singleton()->runJob(
            QueuedJobDescriptor::get()->filter(['Implementation' => AsyncPublishJob::class])->first()->ID
        );

        $this->assertEquals(
            1,
            QueuedJobDescriptor::get()
                ->filter([
                    'Implementation' => AsyncPublishJob::class,
                    'JobStatus' => QueuedJob::STATUS_COMPLETE,
                    'Signature' => AsyncPublisherService::generateSignature($page),
                ])
                ->count()
        );

        /** @var SiteTree|Versioned $refreshedPage */
        $refreshedPage = SiteTree::get()->byID($page->ID);
        $this->assertTrue($refreshedPage->isPublished());
        $this->assertEquals('QueuePublishContent', $refreshedPage->getField('Content'));
        $this->assertFalse($page->pendingAsyncJobsExist());
    }
}
