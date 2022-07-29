<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Fixture;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

class SometimesAsyncPage extends SiteTree implements TestOnly
{

    /**
     * @var bool
     */
    public $shouldAsync = false;

    public int $shouldPreferAsyncCalls = 0;

    /**
     * @return bool
     */
    public function shouldPreferAsync(): bool
    {
        $this->shouldPreferAsyncCalls++;

        return $this->shouldAsync;
    }

}
