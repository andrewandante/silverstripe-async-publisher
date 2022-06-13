<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Fixture;

use AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

class SometimesAsyncPage extends SiteTree implements TestOnly
{
    public $shouldAsync = false;

    public $shouldPreferAsyncCalls = 0;

    public function shouldPreferAsync()
    {
        $this->shouldPreferAsyncCalls++;
        return $this->shouldAsync;
    }
}
