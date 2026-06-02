<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Functional;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncPublish;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncSave;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Session;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\DataObject;
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

        CMSMain::config()->set('model_class', SiteTree::class);

        // The CMS sends a new-record ID in the form "new-{ClassName}-{ParentID}".
        $newId = 'new-' . SiteTree::class . '-0';

        $controller = new CMSMain();
        $controller->getRequest()->setSession(new Session(null));
        $controller->getResponseNegotiator()->setFragmentOverride([]);
        $controller->setURLParams(['Action' => 'EditForm', 'ID' => $newId]);

        $form = $controller->getEditForm($newId);

        $data = [
            'ID' => $newId,
            'ClassName' => SiteTree::class,
            'Title' => 'New Record Test',
        ];

        $controller->asyncSave($data, $form);

        // Exactly one row must exist with the submitted title after asyncSave().
        // The synchronous write must save the real title, not the default
        // "New ..." title, and must not leave a second stray row behind.
        $this->assertEquals(
            1,
            SiteTree::get()->filter(['Title' => 'New Record Test'])->count(),
            'asyncSave() should create exactly one row with the submitted title'
        );

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
            QueuedJobDescriptor::get()->filter(['Implementation' => AsyncSave::class, 'Signature' => $signature])->first()->ID
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
        $this->assertFalse($newPage->pendingAsyncJobsExist());

        // After the job runs, the record in DB should have the submitted title
        // and there must still be exactly one row (no duplicate created by the job).
        $this->assertEquals(
            1,
            SiteTree::get()->filter(['Title' => 'New Record Test'])->count(),
            'Running the job must not create a duplicate row'
        );
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

        CMSMain::config()->set('model_class', SiteTree::class);

        // The CMS sends a new-record ID in the form "new-{ClassName}-{ParentID}".
        $newId = 'new-' . SiteTree::class . '-0';

        $controller = new CMSMain();
        $controller->getRequest()->setSession(new Session(null));
        $controller->getResponseNegotiator()->setFragmentOverride([]);
        $controller->setURLParams(['Action' => 'EditForm', 'ID' => $newId]);

        $form = $controller->getEditForm($newId);

        $data = [
            'ID' => $newId,
            'ClassName' => SiteTree::class,
            'Title' => 'New Record Publish Test',
            'publish' => 1,
        ];

        $controller->asyncSave($data, $form);

        // Exactly one row must exist with the submitted title after asyncSave().
        // The synchronous write must save the real title, not the default
        // "New ..." title, and must not leave a second stray row behind.
        $this->assertEquals(
            1,
            SiteTree::get()->filter(['Title' => 'New Record Publish Test'])->count(),
            'asyncSave() should create exactly one row with the submitted title'
        );

        // The record must exist in DB with a real integer ID after asyncSave().
        /** @var SiteTree|AsyncPublisherExtension $newPage */
        $newPage = SiteTree::get()->filter(['Title' => 'New Record Publish Test'])->first();
        $this->assertNotNull($newPage, 'New record should exist in DB after asyncSave()');
        $this->assertGreaterThan(0, $newPage->ID, 'New record should have a real integer ID');

        // A pending AsyncSave job (which will also publish) should exist for the new record.
        $this->assertTrue($newPage->pendingAsyncJobsExist([AsyncSave::class]));

        $signature = $newPage->generateSignature();

        QueuedJobService::singleton()->runJob(
            QueuedJobDescriptor::get()->filter(['Implementation' => AsyncSave::class, 'Signature' => $signature])->first()->ID
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
        $this->assertFalse($newPage->pendingAsyncJobsExist());

        // After the job runs, the record should be published and there must
        // still be exactly one row (no duplicate created by the job).
        $this->assertEquals(
            1,
            SiteTree::get()->filter(['Title' => 'New Record Publish Test'])->count(),
            'Running the job must not create a duplicate row'
        );
        $publishedPage = SiteTree::get()->byID($newPage->ID);
        $this->assertEquals('New Record Publish Test', $publishedPage->getField('Title'));
        $this->assertTrue($publishedPage->isPublished());
    }

    /**
     * When a validateBeforeAsyncSave extension populates errors, asyncSave() must:
     * - return a 400 response (so the CMS renders it as an error toast)
     * - NOT queue any job
     */
    public function testValidateBeforeAsyncSaveAbortsQueuing(): void
    {
        $this->logInWithPermission();

        CMSMain::config()->set('model_class', SiteTree::class);

        // Register a temporary extension that always blocks publish.
        CMSMain::add_extension(ValidateBeforeAsyncSaveTestExtension::class);

        $controller = new CMSMain();
        $controller->getRequest()->setSession(new Session(null));
        $controller->getResponseNegotiator()->setFragmentOverride([]);

        $page = $this->objFromFixture(SiteTree::class, 'first');
        $controller->setURLParams(['Action' => 'EditForm', 'ID' => (string) $page->ID]);

        $jobCountBefore = QueuedJobDescriptor::get()->filter(['Implementation' => AsyncSave::class])->count();

        $form = $controller->getEditForm($page->ID);
        $response = $controller->asyncSave(['ID' => $page->ID, 'publish' => 1], $form);

        CMSMain::remove_extension(ValidateBeforeAsyncSaveTestExtension::class);

        // Must return 400 so the CMS renders the message as an error toast.
        $this->assertEquals(400, $response->getStatusCode());

        // Must NOT queue a job.
        $jobCountAfter = QueuedJobDescriptor::get()->filter(['Implementation' => AsyncSave::class])->count();
        $this->assertEquals($jobCountBefore, $jobCountAfter, 'No job should be queued when validation blocks');
    }

}

/**
 * Test double used by testValidateBeforeAsyncSaveAbortsQueuing.
 * Always blocks publish with a dummy error message.
 */
class ValidateBeforeAsyncSaveTestExtension extends Extension
{
    public function validateBeforeAsyncSave(DataObject $record, array $data, array &$errors): void
    {
        if (isset($data['publish'])) {
            $errors[] = 'Test block: publish not allowed.';
        }
    }
}
