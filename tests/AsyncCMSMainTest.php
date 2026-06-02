<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncCMSMain;
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

    /**
     * @var string|array
     */
    protected static $fixture_file = 'AsyncCMSMainTest.yml';

    /**
     * @var string[]
     */
    protected static $extra_dataobjects = [MutablePermissionsPage::class];

    /**
     * @var string[][]
     */
    protected static $required_extensions = [CMSMain::class => [AsyncCMSMain::class]];

    public function setUp(): void
    {
        parent::setUp();

        CMSMain::config()->set('tree_class', SiteTree::class);
        SSViewer::config()->set('themes', ['$default']);
    }

    public static function provideDeniedPermissions(): array
    {
        // We're testing the CMS editing workflow, so canCreate is irrelevant
        return [
            ['canEdit'],
            ['canPublish'],
        ];
    }

    /**
     * @dataProvider provideDeniedPermissions
     * @param string $falsePermission
     * @return void
     */
    public function testRecordAndPermissionFailsWithInsufficientPermissions(string $falsePermission): void
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
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        MutablePermissionsPage::$$falsePermission = true;
        // phpcs:enable
    }

    public function testRecordAndPermissionFailsWithBadID(): void
    {
        $this->expectException(HTTPResponse_Exception::class);
        $controller = new CMSMain();
        $controller->asyncGetRecordAndAssertPermissions([
            'ID' => 9001,
            'ClassName' => MutablePermissionsPage::class,
        ]);
    }

    public function testAsyncSaveLogsAQueuedJobToPerformTheAsyncSaving(): void
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

    public function testAsyncStoreState(): void
    {
        $urlParams = ['Action' => 'index'];
        $controller = new Controller();
        $controller->setURLParams($urlParams);
        $extension = new AsyncCMSMain();
        $extension->setOwner($controller);
        $this->assertSame(['URLParams' => $urlParams], $extension->asyncStoreState());
    }

    public function testAsyncRestoreState(): void
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
