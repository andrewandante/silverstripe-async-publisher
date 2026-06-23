<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Tests\Functional;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * Test double used by testValidateBeforeAsyncSaveAbortsQueuing.
 * Always blocks publish with a dummy error message.
 */
class ValidateBeforeAsyncSaveTestExtension extends Extension
{

    public function validateBeforeAsyncSave(DataObject $record, array $data, array &$errors): void
    {
        if (!isset($data['publish'])) {
            return;
        }

        $errors[] = 'Test block: publish not allowed.';
    }

}
