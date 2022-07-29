<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Fixture;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

class MutablePermissionsPage extends SiteTree implements TestOnly
{

    /**
     * @var string[]
     */
    private static $extensions = [AsyncPublisherExtension::class];

    /**
     * @var bool
     */
    public static $canCreate = true;

    /**
     * @var bool
     */
    public static $canEdit = true;

    /**
     * @var bool
     */
    public static $canPublish = true;

    /**
     * @param null $member
     * @param array $context
     * @return bool
     */
    public function canCreate($member = null, $context = [])
    {
        return self::$canCreate;
    }

    /**
     * @param null $member
     * @return bool
     */
    public function canEdit($member = null)
    {
        return self::$canEdit;
    }

    /**
     * @param null $member
     * @return bool
     */
    public function canPublish($member = null)
    {
        return self::$canPublish;
    }

}
