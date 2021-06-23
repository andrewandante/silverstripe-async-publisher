<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Functional;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use Page;
use SilverStripe\Dev\FunctionalTest;

class AsyncPublisherTest extends FunctionalTest
{
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        Page::add_extension(AsyncPublisherExtension::class);
    }

    protected static $fixture_file = 'fixtures.yml';

    public function testButtonsUpdate()
    {
        $this->logInWithPermission();
        /** @var Page $page */
        $page = $this->objFromFixture(Page::class, 'first');
        $this->get($page->getCMSEditLink());
        $this->assertExactMatchBySelector(
            '#Form_EditForm_MajorActions_Holder #Form_EditForm_action_async_save span',
            ['Saved']
        );
        $this->assertExactMatchBySelector(
            '#Form_EditForm_MajorActions_Holder #Form_EditForm_action_async_publish span',
            ['Published']
        );
        $this->assertExactMatchBySelector(
            '#ActionMenus_MoreOptions #Form_EditForm_action_force_save.value',
            ['Force Save']
        );
        $this->assertExactMatchBySelector(
            '#ActionMenus_MoreOptions #Form_EditForm_action_force_publish.value',
            ['Force Publish']
        );
    }
}
