<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Functional;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncSave;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncPublish;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class AsyncPublisherTest extends FunctionalTest
{
    protected static $required_extensions = [
        SiteTree::class => [AsyncPublisherExtension::class],
    ];

    protected static $fixture_file = 'AsyncPublisherTest.yml';

    public function testButtonsUpdate()
    {
        $this->logInWithPermission();
        /** @var SiteTree|AsyncPublisherExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'first');
        $this->get($page->CMSEditLink());
        $this->assertExactMatchBySelector(
            '#Form_EditForm_MajorActions_Holder #Form_EditForm_action_asyncSave span',
            ['Saved']
        );
        $this->assertExactMatchBySelector(
            '#Form_EditForm_MajorActions_Holder #Form_EditForm_action_asyncPublish span',
            ['Published']
        );
        // phpcs:disable Generic.Files.LineLength.TooLong
        $this->assertExactMatchBySelector(
            '#ActionMenus_MoreOptions #Form_EditForm_action_save span',
            ['Saved (immediate)']
        );
        $this->assertExactMatchBySelector(
            '#ActionMenus_MoreOptions #Form_EditForm_action_publish span',
            ['Published (immediate)']
        );
        // phpcs:enable
    }

    public function testQueueSave()
    {
        $this->logInWithPermission();
        /** @var SiteTree|AsyncPublisherExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'first');
        $this->get($page->CMSEditLink());
        $response = $this->submitForm('Form_EditForm', 'action_asyncSave', [
            'Content' => 'QueueSaveContent'
        ]);

        $signature = $page->generateSignature();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($page->pendingAsyncJobsExist([AsyncSave::class]));
        $this->assertFalse($page->pendingAsyncJobsExist([AsyncPublish::class]));

        QueuedJobService::singleton()->runJob(
            QueuedJobDescriptor::get()->filter(['Implementation' => AsyncSave::class])->first()->ID
        );

        $this->assertEquals(
            1,
            QueuedJobDescriptor::get()
                ->filter([
                    'Implementation' => AsyncSave::class,
                    'JobStatus' => QueuedJob::STATUS_COMPLETE,
                    'Signature' => $signature,
                ])
                ->count()
        );
        $this->assertFalse($page->pendingAsyncJobsExist([AsyncPublish::class]));

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
        $response = $this->submitForm('Form_EditForm', 'action_asyncPublish', [
            'Content' => 'QueuePublishContent'
        ]);

        $signature = $page->generateSignature();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($page->pendingAsyncJobsExist([AsyncSave::class]));
        $this->assertFalse($page->pendingAsyncJobsExist([AsyncPublish::class]));

        QueuedJobService::singleton()->runJob(
            QueuedJobDescriptor::get()->filter(['Implementation' => AsyncSave::class])->first()->ID
        );

        $this->assertEquals(
            1,
            QueuedJobDescriptor::get()
                ->filter([
                    'Implementation' => AsyncSave::class,
                    'JobStatus' => QueuedJob::STATUS_COMPLETE,
                    'Signature' => $signature,
                ])
                ->count()
        );
        $this->assertTrue($page->pendingAsyncJobsExist([AsyncPublish::class]));

        $refreshedPage = SiteTree::get()->byID($page->ID);
        $this->assertFalse($refreshedPage->isPublished());
        $this->assertEquals('QueuePublishContent', $refreshedPage->getField('Content'));


        QueuedJobService::singleton()->runJob(
            QueuedJobDescriptor::get()->filter(['Implementation' => AsyncPublish::class])->first()->ID
        );

        $this->assertEquals(
            1,
            QueuedJobDescriptor::get()
                ->filter([
                    'Implementation' => AsyncPublish::class,
                    'JobStatus' => QueuedJob::STATUS_COMPLETE,
                    'Signature' => $signature,
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
