<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Service;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormField;
use SilverStripe\ORM\DataObject;

class AsyncPublisherService
{
    use Injectable;
    use Configurable;

    private static $dependencies = [
        'FormDataCache' => '%$' . CacheInterface::class . '.CMSMain_AsyncPublisher',
    ];

    /**
     * @config
     * @var int
     */
    private static $cache_ttl = 600;

    /**
     * @config
     * @var array
     */
    private static $apply_to_classes = [];

    /**
     * @var CacheInterface
     */
    protected $formDataCache;

    public function cacheFormSubmission($record, Form $form)
    {
        $signature = self::generateSignature($record);
        $cachettl = $this->config()->get('cache_ttl');
        $formData = $form->getData();
        $formDataFields = $form->Fields()->dataFields();
        $fieldsMap = [];
        foreach ($formDataFields as $field) {
            $fieldsMap[] = [
                'className' => get_class($field),
                'fieldName' => $field->getName(),
                'value' => $field->Value(),
            ];
        }
        $this->formDataCache->set($signature . "-data", $form->getData(), $cachettl);
        $this->formDataCache->set($signature . "-fields", $fieldsMap, $cachettl);

        return $signature;
    }

    public function getFormSubmissionBySignature(string $signature)
    {
        $formData = $this->formDataCache->get($signature . "-data");
        $formFields = $this->formDataCache->get($signature . "-fields");
        $form = Form::create();
        foreach ($formFields as $formFieldData) {
            $field = $formFieldData['className']::create($formFieldData['fieldName']);
            $field->setValue($formFieldData['value']);
            $form->Fields()->add($field);
        }
        return $form->loadDataFrom($formData, Form::MERGE_AS_SUBMITTED_VALUE);
    }

    public static function generateSignature($record)
    {
        return md5(sprintf("%s-%s", $record->ID, $record->ClassName));
    }

    public function getFormDataCache()
    {
        return $this->formDataCache;
    }

    /**
     * @param CacheInterface $cache
     * @return $this
     */
    public function setFormDataCache(CacheInterface $cache)
    {
        $this->formDataCache = $cache;

        return $this;
    }
}
