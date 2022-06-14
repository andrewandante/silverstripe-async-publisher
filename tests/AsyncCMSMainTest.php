<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncCMSMain;
use AndrewAndante\SilverStripe\AsyncPublisher\Job\AsyncSave;
use AndrewAndante\SilverStripe\AsyncPublisher\Tests\Fixture\MutablePermissionsPage;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\View\SSViewer;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

class AsyncCMSMainTest extends SapphireTest
{
    protected static $fixture_file = 'AsyncCMSMainTest.yml';

    protected static $extra_dataobjects = [MutablePermissionsPage::class];

    protected static $required_extensions = [CMSMain::class => [AsyncCMSMain::class]];

    public function setUp()
    {
        parent::setUp();
        CMSMain::config()->set('tree_class', SiteTree::class);
        SSViewer::config()->set('themes', ['$default']);
    }

    public function provideDeniedPermissions()
    {
        // We're testing the CMS editing workflow, so canCreate is irrelevant
        return [
            ['canEdit'],
            ['canPublish'],
        ];
    }

    /**
     * @dataProvider provideDeniedPermissions
     *
     * @param string $falsePermission
     * @return void
     */
    public function testRecordAndPermissionFailsWithInsufficientPermissions($falsePermission)
    {
        MutablePermissionsPage::$$falsePermission = false;
        $page = $this->objFromFixture(MutablePermissionsPage::class, 'test');
        $controller = new CMSMain();
        $controller->getRequest()->setSession(new Session(null));
        $data = [
            'ID' => $page->ID,
            'ClassName' => MutablePermissionsPage::class,
        ];
        if ($falsePermission === 'canPublish') {
            $data['publish'] = true;
        }
        $response = $controller->asyncGetRecordAndAssertPermissions($data);
        $this->assertInstanceOf(HTTPResponse::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        MutablePermissionsPage::$$falsePermission = true;
    }

    public function testRecordAndPermissionFailsWithBadID()
    {
        $this->expectException(HTTPResponse_Exception::class);
        $controller = new CMSMain();
        $controller->asyncGetRecordAndAssertPermissions([
            'ID' => 9001,
            'ClassName' => MutablePermissionsPage::class,
        ]);
    }

    public function testAsyncSaveLogsAQueuedJobToPerformTheAsyncSaving()
    {
        $page = $this->objFromFixture(MutablePermissionsPage::class, 'test');
        $controller = new CMSMain();
        $controller->getRequest()->setSession(new Session(null));
        $controller->getResponseNegotiator()->setFragmentOverride([]);
        $controller->setURLParams(['Action' => 'EditForm', 'ID' => $page->ID]);
        $form = $controller->getEditForm($page->ID);
        $page->update(['Title' => 'Updated item save test']);
        $controller->asyncSave($page->toMap(), $form);
        $jobs = QueuedJobDescriptor::get();
        $this->assertCount(1, $jobs);
        $this->assertTrue($page->pendingAsyncJobsExist());
    }

    public function testAsyncStoreState()
    {
        $urlParams = ['Action' => 'index'];
        $controller = new Controller();
        $controller->setURLParams($urlParams);
        $extension = new AsyncCMSMain();
        $extension->setOwner($controller);
        $this->assertSame(['URLParams' => $urlParams], $extension->asyncStoreState());
    }

    public function testAsyncRestoreState()
    {
        $updatedUrlParams = ['Action' => 'edit', 'ID' => 12];
        $state = ['URLParams' => $updatedUrlParams];
        $controller = new Controller();
        $controller->setURLParams(['Action' => 'index']);
        $extension = new AsyncCMSMain();
        $extension->setOwner($controller);
        $extension->asyncRestoreState($state);
        $this->assertSame($updatedUrlParams, $controller->getURLParams());
    }
}
