<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncSave;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncPublish;
use AndrewAndante\SilverStripe\AsyncPublisher\Tests\Fixture\SometimesAsyncPage;
use AndrewAndante\SilverStripe\AsyncPublisher\Tests\Fixture\TestPage;
use SilverStripe\Control\Controller;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\Form;
use Symbiote\QueuedJobs\Services\DefaultQueueHandler;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class AsyncPublisherExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestPage::class,
    ];

    protected static $required_extensions = [
        TestPage::class => [AsyncPublisherExtension::class],
        SometimesAsyncPage::class => [AsyncPublisherExtension::class],
    ];

    public function testPendingAsyncJobsExistWithAsyncSave()
    {
        $page = new TestPage();
        $page->Title = 'Initial page name';
        $page->write();

        $this->assertFalse($page->pendingAsyncJobsExist());

        $controller = new Controller();
        $details = $page->toMap();
        $details['Title'] = 'Updated Heading';
        $job = new AsyncSave($controller, 'Form', $details, $page->generateSignature());

        $queueService = new QueuedJobService();
        $queueService->queueHandler = new DefaultQueueHandler();
        $queueService->queueJob($job);

        $this->assertTrue($page->pendingAsyncJobsExist());
    }

    public function testPendingAsyncJobsExistWithAsyncPublish()
    {
        $page = new TestPage();
        $page->write();

        $this->assertFalse($page->pendingAsyncJobsExist());

        $job = new AsyncPublish($page);

        $queueService = new QueuedJobService();
        $queueService->queueHandler = new DefaultQueueHandler();
        $queueService->queueJob($job);

        $this->assertTrue($page->pendingAsyncJobsExist());
    }

    public function testPreferAsyncDelegatesToOwner()
    {
        $page = new SometimesAsyncPage();
        $page->shouldAsync = true;
        $this->assertTrue($page->preferAsync());
        $page->shouldAsync = false;
        $this->assertFalse($page->preferAsync());
        $this->assertSame(2, $page->shouldPreferAsyncCalls);
    }

    public function testJobSignatureGenerationIsConsistentForExistingObjectsIndependentOfDataVariance()
    {
        $page = new TestPage(['ID' => 12, 'Title' => 'Intro']);
        $oldSignature = $page->generateSignature();
        foreach (['one', 'two', 'three', 'four', 'five', "everybody in the car so come on let's ride"] as $newTitle) {
            $newObjectSameId = new TestPage(['ID' => 12, 'Title' => 'Trumpet']);
            $newObjectSameId->update(['Title' => $newTitle]);
            $newSignature = $newObjectSameId->generateSignature();
            $this->assertSame($oldSignature, $newSignature);
            $oldSignature = $newSignature;
        }
    }
}
