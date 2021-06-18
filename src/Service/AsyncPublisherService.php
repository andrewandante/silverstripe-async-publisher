<?php

namespace AndrewAndante\SilverStripe\AsyncPublisher\Service;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forms\Form;

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
        $this->formDataCache->set($signature, $form->getData(), $cachettl);

        return $signature;
    }

    public function getFormSubmissionBySignature(string $signature)
    {
        return Form::create()->loadDataFrom($this->formDataCache->get($signature));
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
