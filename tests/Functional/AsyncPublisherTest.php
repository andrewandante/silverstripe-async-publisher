<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Functional;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncPublish;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncSave;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Session;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class AsyncPublisherTest extends FunctionalTest
{

    /**
     * @var array
     */
    protected static $required_extensions = [
        SiteTree::class => [AsyncPublisherExtension::class],
    ];

    /**
     * @var string|array
     */
    protected static $fixture_file = 'AsyncPublisherTest.yml';

    public function testButtonsUpdate(): void
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

    public function testQueueSave(): void
    {
        $this->logInWithPermission();
        /** @var SiteTree|AsyncPublisherExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'first');
        $this->get($page->CMSEditLink());
        $response = $this->submitForm('Form_EditForm', 'action_asyncSave', [
            'Content' => 'QueueSaveContent',
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
        $this->assertFalse($page->pendingAsyncJobsExist());

        $refreshedPage = SiteTree::get()->byID($page->ID);
        $this->assertEquals('QueueSaveContent', $refreshedPage->getField('Content'));
        $this->assertFalse($refreshedPage->isPublished());
    }

    public function testQueueStraightToPublish(): void
    {
        $this->logInWithPermission();
        /** @var SiteTree|AsyncPublisherExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'first');
        $this->get($page->CMSEditLink());
        $response = $this->submitForm('Form_EditForm', 'action_asyncPublish', [
            'Content' => 'QueuePublishContent',
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
        $this->assertFalse($page->pendingAsyncJobsExist());

        $refreshedPage = SiteTree::get()->byID($page->ID);
        $this->assertTrue($refreshedPage->isPublished());
        $this->assertEquals('QueuePublishContent', $refreshedPage->getField('Content'));
    }

    public function testPublishRecursive(): void
    {
        /** @var SiteTree|AsyncPublisherExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'first');
        $page->Content = 'PublishRecursiveContent';
        $page->writeToStage(Versioned::DRAFT);
        $signature = $page->generateSignature();

        $this->assertFalse($page->pendingAsyncJobsExist());

        /** @var SiteTree|AsyncPublisherExtension $refreshedPage */
        $refreshedPage = SiteTree::get()->byID($page->ID);
        $this->assertFalse($refreshedPage->isPublished());

        $refreshedPage->publishRecursive();
        $this->assertFalse($page->pendingAsyncJobsExist([AsyncSave::class]));
        $this->assertTrue($page->pendingAsyncJobsExist([AsyncPublish::class]));

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
        $this->assertFalse($page->pendingAsyncJobsExist());

        /** @var SiteTree|AsyncPublisherExtension $rerefreshedPage */
        $rerefreshedPage = SiteTree::get()->byID($page->ID);
        $this->assertTrue($rerefreshedPage->isPublished());
        $this->assertEquals('PublishRecursiveContent', $rerefreshedPage->getField('Content'));
    }

    /**
     * A new record (ID = 'new-1') must be written to DB synchronously before the job runs,
     * so the job can find the record by a real integer ID.
     */
    public function testQueueSaveNewRecord(): void
    {
        $this->logInWithPermission();

        CMSMain::config()->set('tree_class', SiteTree::class);

        $controller = new CMSMain();
        $controller->getRequest()->setSession(new Session(null));
        $controller->getResponseNegotiator()->setFragmentOverride([]);
        $controller->setURLParams(['Action' => 'EditForm', 'ID' => 'new-1']);

        $form = $controller->getEditForm('new-1');

        $data = [
            'ID' => 'new-1',
            'ClassName' => SiteTree::class,
            'Title' => 'New Record Test',
        ];

        $controller->asyncSave($data, $form);

        // The record must exist in DB with a real integer ID after asyncSave().
        /** @var SiteTree|AsyncPublisherExtension $newPage */
        $newPage = SiteTree::get()->filter(['Title' => 'New Record Test'])->first();
        $this->assertNotNull($newPage, 'New record should exist in DB after asyncSave()');
        $this->assertGreaterThan(0, $newPage->ID, 'New record should have a real integer ID');

        // A pending AsyncSave job should exist for the new record.
        $this->assertTrue($newPage->pendingAsyncJobsExist([AsyncSave::class]));
        $this->assertFalse($newPage->pendingAsyncJobsExist([AsyncPublish::class]));

        $signature = $newPage->generateSignature();

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

        // After the job runs, the record in DB should have the submitted title.
        $savedPage = SiteTree::get()->byID($newPage->ID);
        $this->assertEquals('New Record Test', $savedPage->getField('Title'));

        // Save only — must NOT be published.
        $this->assertFalse($savedPage->isPublished());
    }

    /**
     * A new record with publish flag set must be written synchronously and then published
     * once the async job runs.
     */
    public function testQueuePublishNewRecord(): void
    {
        $this->logInWithPermission();

        CMSMain::config()->set('tree_class', SiteTree::class);

        $controller = new CMSMain();
        $controller->getRequest()->setSession(new Session(null));
        $controller->getResponseNegotiator()->setFragmentOverride([]);
        $controller->setURLParams(['Action' => 'EditForm', 'ID' => 'new-1']);

        $form = $controller->getEditForm('new-1');

        $data = [
            'ID' => 'new-1',
            'ClassName' => SiteTree::class,
            'Title' => 'New Record Publish Test',
            'publish' => 1,
        ];

        $controller->asyncSave($data, $form);

        // The record must exist in DB with a real integer ID after asyncSave().
        /** @var SiteTree|AsyncPublisherExtension $newPage */
        $newPage = SiteTree::get()->filter(['Title' => 'New Record Publish Test'])->first();
        $this->assertNotNull($newPage, 'New record should exist in DB after asyncSave()');
        $this->assertGreaterThan(0, $newPage->ID, 'New record should have a real integer ID');

        // A pending AsyncSave job (which will also publish) should exist for the new record.
        $this->assertTrue($newPage->pendingAsyncJobsExist([AsyncSave::class]));

        $signature = $newPage->generateSignature();

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

        // After the job runs, the record should be published.
        $publishedPage = SiteTree::get()->byID($newPage->ID);
        $this->assertEquals('New Record Publish Test', $publishedPage->getField('Title'));
        $this->assertTrue($publishedPage->isPublished());
    }

}
