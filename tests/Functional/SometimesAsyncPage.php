<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Functional;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

class SometimesAsyncPage extends SiteTree implements TestOnly
{
    private static $table_name = 'SometimesAsyncPage';

    private static $extensions = [
        AsyncPublisherExtension::class,
    ];

    private static $db = [
        'IsLarge' => 'Boolean',
        'IsSlow' => 'Boolean',
    ];

    public function shouldPreferAsync()
    {
        return $this->IsLarge || $this->IsSlow;
    }
}
