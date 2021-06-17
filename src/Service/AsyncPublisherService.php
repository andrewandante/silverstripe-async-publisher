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
        'formDataCache' => '%$' . CacheInterface::class . '.CMSMain_AsyncPublisher',
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

    public function cacheFormSubmission($data, Form $form)
    {
        $signature = $this->generateSignature($data, $form);
        $this->formDataCache->set($signature, $form, $this->config->get('cache_ttl'));

        return $this->formDataCache->get($signature);
    }

    public function getFormSubmissionBySignature(string $signature)
    {
        return $this->formDataCache->get($signature);
    }

    public function generateSignature($data, Form $form)
    {
        return md5(sprintf("%s-%s", json_encode($data), $form->getHTMLID()));
    }

    public function getFormDataCache()
    {
        return $this->formDataCache;
    }
}
