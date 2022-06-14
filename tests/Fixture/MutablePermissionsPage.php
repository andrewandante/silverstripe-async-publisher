<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Fixture;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

class MutablePermissionsPage extends SiteTree implements TestOnly
{
    private static $extensions = [AsyncPublisherExtension::class];

    public static $canCreate = true;

    public static $canEdit = true;

    public static $canPublish = true;

    public function canCreate($member = null, $context = [])
    {
        return self::$canCreate;
    }

    public function canEdit($member = null)
    {
        return self::$canEdit;
    }

    public function canPublish($member = null)
    {
        return self::$canPublish;
    }
}
