<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation;

use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\Exception\NotFoundResourceException;
use Symfony\Component\Config\ConfigCacheInterface;
use Symfony\Component\Config\ConfigCacheFactoryInterface;
use Symfony\Component\Config\ConfigCacheFactory;

/**
 * Translator.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @api
 */
class Translator implements TranslatorInterface, TranslatorBagInterface
{
    /**
     * @var MessageCatalogueInterface[]
     */
    protected $catalogues = array();

    /**
     * @var string
     */
    protected $locale;

    /**
     * @var array
     */
    private $fallbackLocales = array();

    /**
     * @var LoaderInterface[]
     */
    private $loaders = array();

    /**
     * @var array
     */
    private $resources = array();

    /**
     * @var MessageSelector
     */
    private $selector;

    /**
     * @var string
     */
    private $cacheDir;

    /**
     * @var bool
     */
    private $debug;

    /**
     * @var ConfigCacheFactoryInterface|null
     */
    private $configCacheFactory;

    /**
     * Constructor.
     *
     * @param string               $locale   The locale
     * @param MessageSelector|null $selector The message selector for pluralization
     * @param string|null          $cacheDir The directory to use for the cache
     * @param bool                 $debug    Use cache in debug mode ?
     *
     * @throws \InvalidArgumentException If a locale contains invalid characters
     *
     * @api
     */
    public function __construct($locale, MessageSelector $selector = null, $cacheDir = null, $debug = false)
    {
        $this->setLocale($locale);
        $this->selector = $selector ?: new MessageSelector();
        $this->cacheDir = $cacheDir;
        $this->debug = $debug;
    }

    /**
     * Sets the ConfigCache factory to use.
     *
     * @param ConfigCacheFactoryInterface $configCacheFactory
     */
    public function setConfigCacheFactory(ConfigCacheFactoryInterface $configCacheFactory)
    {
        $this->configCacheFactory = $configCacheFactory;
    }

    /**
     * Adds a Loader.
     *
     * @param string          $format The name of the loader (@see addResource())
     * @param LoaderInterface $loader A LoaderInterface instance
     *
     * @api
     */
    public function addLoader($format, LoaderInterface $loader)
    {
        $this->loaders[$format] = $loader;
    }

    /**
     * Adds a Resource.
     *
     * @param string $format   The name of the loader (@see addLoader())
     * @param mixed  $resource The resource name
     * @param string $locale   The locale
     * @param string $domain   The domain
     *
     * @throws \InvalidArgumentException If the locale contains invalid characters
     *
     * @api
     */
    public function addResource($format, $resource, $locale, $domain = null)
    {
        if (null === $domain) {
            $domain = 'messages';
        }

        $this->assertValidLocale($locale);

        $this->resources[$locale][] = array($format, $resource, $domain);

        if (in_array($locale, $this->fallbackLocales)) {
            $this->catalogues = array();
        } else {
            unset($this->catalogues[$locale]);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @api
     */
    public function setLocale($locale)
    {
        $this->assertValidLocale($locale);
        $this->locale = $locale;
    }

    /**
     * {@inheritdoc}
     *
     * @api
     */
    public function getLocale()
    {
        return $this->locale;
    }

    /**
     * Sets the fallback locales.
     *
     * @param array $locales The fallback locales
     *
     * @throws \InvalidArgumentException If a locale contains invalid characters
     *
     * @api
     */
    public function setFallbackLocales(array $locales)
    {
        // needed as the fallback locales are linked to the already loaded catalogues
        $this->catalogues = array();

        foreach ($locales as $locale) {
            $this->assertValidLocale($locale);
        }

        $this->fallbackLocales = $locales;
    }

    /**
     * Gets the fallback locales.
     *
     * @return array $locales The fallback locales
     *
     * @api
     */
    public function getFallbackLocales()
    {
        return $this->fallbackLocales;
    }

    /**
     * {@inheritdoc}
     *
     * @api
     */
    public function trans($id, array $parameters = array(), $domain = null, $locale = null)
    {
        if (null === $locale) {
            $locale = $this->getLocale();
        } else {
            $this->assertValidLocale($locale);
        }

        if (null === $domain) {
            $domain = 'messages';
        }

        if (!isset($this->catalogues[$locale])) {
            $this->loadCatalogue($locale);
        }

        return strtr($this->catalogues[$locale]->get((string) $id, $domain), $parameters);
    }

    /**
     * {@inheritdoc}
     *
     * @api
     */
    public function transChoice($id, $number, array $parameters = array(), $domain = null, $locale = null)
    {
        if (null === $locale) {
            $locale = $this->getLocale();
        } else {
            $this->assertValidLocale($locale);
        }

        if (null === $domain) {
            $domain = 'messages';
        }

        if (!isset($this->catalogues[$locale])) {
            $this->loadCatalogue($locale);
        }

        $id = (string) $id;

        $catalogue = $this->catalogues[$locale];
        while (!$catalogue->defines($id, $domain)) {
            if ($cat = $catalogue->getFallbackCatalogue()) {
                $catalogue = $cat;
                $locale = $catalogue->getLocale();
            } else {
                break;
            }
        }

        return strtr($this->selector->choose($catalogue->get($id, $domain), (int) $number, $locale), $parameters);
    }

    /**
     * {@inheritdoc}
     */
    public function getCatalogue($locale = null)
    {
        if (null === $locale) {
            $locale = $this->getLocale();
        }

        if (!isset($this->catalogues[$locale])) {
            $this->loadCatalogue($locale);
        }

        return $this->catalogues[$locale];
    }

    /**
     * Gets the loaders.
     *
     * @return array LoaderInterface[]
     */
    protected function getLoaders()
    {
        return $this->loaders;
    }

    /**
     * Collects all messages for the given locale.
     *
     * @param string|null $locale Locale of translations, by default is current locale
     *
     * @return array[array] indexed by catalog
     */
    public function getMessages($locale = null)
    {
        if (null === $locale) {
            $locale = $this->getLocale();
        }

        if (!isset($this->catalogues[$locale])) {
            $this->loadCatalogue($locale);
        }

        $catalogues = array();
        $catalogues[] = $catalogue = $this->catalogues[$locale];
        while ($catalogue = $catalogue->getFallbackCatalogue()) {
            $catalogues[] = $catalogue;
        }
        $messages = array();
        for ($i = count($catalogues) - 1; $i >= 0; $i--) {
            $localeMessages = $catalogues[$i]->all();
            $messages = array_replace_recursive($messages, $localeMessages);
        }

        return $messages;
    }

    /**
     * @param string $locale
     */
    protected function loadCatalogue($locale)
    {
        if (null === $this->cacheDir) {
            $this->initializeCatalogue($locale);
        } else {
            $this->initializeCacheCatalogue($locale);
        }
    }

    /**
     * @param string $locale
     */
    protected function initializeCatalogue($locale)
    {
        $this->assertValidLocale($locale);

        try {
            $this->doLoadCatalogue($locale);
        } catch (NotFoundResourceException $e) {
            if (!$this->computeFallbackLocales($locale)) {
                throw $e;
            }
        }
        $this->loadFallbackCatalogues($locale);
    }

    /**
     * @param string $locale
     */
    private function initializeCacheCatalogue($locale)
    {
        if (isset($this->catalogues[$locale])) {
            /* Catalogue already initialized. */
            return;
        }

        $this->assertValidLocale($locale);
        $cacheFile = $this->cacheDir.'/catalogue.'.$locale.'.php';
        $self = $this; // required for PHP 5.3 where "$this" cannot be use()d in anonymous functions. Change in Symfony 3.0.
        $cache = $this->getConfigCacheFactory()->cache($cacheFile,
            function (ConfigCacheInterface $cache) use ($self, $locale) {
                $self->dumpCatalogue($locale, $cache);
            }
        );

        if (isset($this->catalogues[$locale])) {
            /* Catalogue has been initialized as it was written out to cache. */
            return;
        }

        /* Read catalogue from cache. */
        $catalogue = include $cache->getPath();

        /*
         * Gracefully handle the case when the cached catalogue is in an "old" format, without a resourcesHash
         */
        $resourcesHash = null;
        if (is_array($catalogue)) {
            list($catalogue, $resourcesHash) = $catalogue;
        }

        if ($this->debug && $resourcesHash !== $this->getResourcesHash($locale)) {
            /*
             * This approach of resource checking has the disadvantage that a second
             * type of freshness check happens based on content *inside* the cache, while
             * the idea of ConfigCache is to make this check transparent to the client (and keeps
             * the resources in a .meta file).
             *
             * Thus, we might run into the unfortunate situation that we just thought (a few lines above)
             * that the cache is fresh -- and now that we look into it, we figure it's not.
             *
             * For now, just unlink the cache and try again. See
             * https://github.com/symfony/symfony/pull/11862#issuecomment-54634631 and/or
             * https://github.com/symfony/symfony/issues/7176 for possible better approaches.
             */
            unlink($cacheFile);
            $this->initializeCacheCatalogue($locale);
        } else {
            /* Initialize with catalogue from cache. */
            $this->catalogues[$locale] = $catalogue;
        }
    }

    /**
     * This method is public because it needs to be callable from a closure in PHP 5.3. It should be made protected (or even private, if possible) in 3.0.
     * @internal
     */
    public function dumpCatalogue($locale, ConfigCacheInterface $cache)
    {
        $this->initializeCatalogue($locale);
        $fallbackContent = $this->getFallbackContent($this->catalogues[$locale]);

        $content = sprintf(<<<EOF
<?php

use Symfony\Component\Translation\MessageCatalogue;

\$resourcesHash = '%s';
\$catalogue = new MessageCatalogue('%s', %s);

%s
return array(\$catalogue, \$resourcesHash);

EOF
            ,
            $this->getResourcesHash($locale),
            $locale,
            var_export($this->catalogues[$locale]->all(), true),
            $fallbackContent
        );

        $cache->write($content, $this->catalogues[$locale]->getResources());
    }

    private function getFallbackContent(MessageCatalogue $catalogue)
    {
        if (!$this->debug) {
            // merge all fallback catalogues messages into $catalogue
            $fallbackCatalogue = $catalogue->getFallbackCatalogue();
            $messages = $catalogue->all();
            while ($fallbackCatalogue) {
                $messages = array_replace_recursive($fallbackCatalogue->all(), $messages);
                $fallbackCatalogue = $fallbackCatalogue->getFallbackCatalogue();
            }
            foreach ($messages as $domain => $domainMessages) {
                $catalogue->add($domainMessages, $domain);
            }

            return '';
        }

        $fallbackContent = '';
        $current = '';
        $replacementPattern = '/[^a-z0-9_]/i';
        $fallbackCatalogue = $catalogue->getFallbackCatalogue();
        while ($fallbackCatalogue) {
            $fallback = $fallbackCatalogue->getLocale();
            $fallbackSuffix = ucfirst(preg_replace($replacementPattern, '_', $fallback));
            $currentSuffix = ucfirst(preg_replace($replacementPattern, '_', $current));

            $fallbackContent .= sprintf(<<<EOF
\$catalogue%s = new MessageCatalogue('%s', %s);
\$catalogue%s->addFallbackCatalogue(\$catalogue%s);

EOF
                ,
                $fallbackSuffix,
                $fallback,
                var_export($fallbackCatalogue->all(), true),
                $currentSuffix,
                $fallbackSuffix
            );
            $current = $fallbackCatalogue->getLocale();
            $fallbackCatalogue = $fallbackCatalogue->getFallbackCatalogue();
        }

        return $fallbackContent;
    }

    private function getResourcesHash($locale)
    {
        if (!isset($this->resources[$locale])) {
            return '';
        }

        return sha1(serialize($this->resources[$locale]));
    }

    private function doLoadCatalogue($locale)
    {
        $this->catalogues[$locale] = new MessageCatalogue($locale);

        if (isset($this->resources[$locale])) {
            foreach ($this->resources[$locale] as $resource) {
                if (!isset($this->loaders[$resource[0]])) {
                    throw new \RuntimeException(sprintf('The "%s" translation loader is not registered.', $resource[0]));
                }
                $this->catalogues[$locale]->addCatalogue($this->loaders[$resource[0]]->load($resource[1], $locale, $resource[2]));
            }
        }
    }

    private function loadFallbackCatalogues($locale)
    {
        $current = $this->catalogues[$locale];

        foreach ($this->computeFallbackLocales($locale) as $fallback) {
            if (!isset($this->catalogues[$fallback])) {
                $this->doLoadCatalogue($fallback);
            }

            $current->addFallbackCatalogue($this->catalogues[$fallback]);
            $current = $this->catalogues[$fallback];
        }
    }

    protected function computeFallbackLocales($locale)
    {
        $locales = array();
        foreach ($this->fallbackLocales as $fallback) {
            if ($fallback === $locale) {
                continue;
            }

            $locales[] = $fallback;
        }

        if (strrchr($locale, '_') !== false) {
            array_unshift($locales, substr($locale, 0, -strlen(strrchr($locale, '_'))));
        }

        return array_unique($locales);
    }

    /**
     * Asserts that the locale is valid, throws an Exception if not.
     *
     * @param string $locale Locale to tests
     *
     * @throws \InvalidArgumentException If the locale contains invalid characters
     */
    protected function assertValidLocale($locale)
    {
        if (1 !== preg_match('/^[a-z0-9@_\\.\\-]*$/i', $locale)) {
            throw new \InvalidArgumentException(sprintf('Invalid "%s" locale.', $locale));
        }
    }

    /**
     * Provides the ConfigCache factory implementation, falling back to a
     * default implementation if necessary.
     *
     * @return ConfigCacheFactoryInterface $configCacheFactory
     */
    private function getConfigCacheFactory()
    {
        if (!$this->configCacheFactory) {
            $this->configCacheFactory = new ConfigCacheFactory($this->debug);
        }

        return $this->configCacheFactory;
    }
}
