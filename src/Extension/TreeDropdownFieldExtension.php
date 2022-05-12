<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Extension;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extension;

class TreeDropdownFieldExtension extends Extension
{
    public function updateArrayForAsyncPublisher(array &$data)
    {
        $data['source'] = $this->getOwner()->getSourceObject();
        $data['createArgs'] = [
            'fieldName',
            'title',
            'source',
        ];
    }
}
