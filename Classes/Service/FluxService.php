<?php
namespace FluidTYPO3\Flux\Service;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Content\TypeDefinition\FluidFileBased\DropInContentTypeDefinition;
use FluidTYPO3\Flux\Core;
use FluidTYPO3\Flux\Form;
use FluidTYPO3\Flux\Form\Transformation\FormDataTransformer;
use FluidTYPO3\Flux\Integration\Resolver;
use FluidTYPO3\Flux\Provider\PageProvider;
use FluidTYPO3\Flux\Provider\ProviderInterface;
use FluidTYPO3\Flux\Provider\ProviderResolver;
use FluidTYPO3\Flux\Utility\ExtensionConfigurationUtility;
use FluidTYPO3\Flux\Utility\ExtensionNamingUtility;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;
use TYPO3\CMS\Fluid\View\TemplatePaths;

/**
 * Flux FlexForm integration Service
 *
 * Main API Service for interacting with Flux-based FlexForms
 */
class FluxService implements SingletonInterface
{
    /**
     * @var ConfigurationManagerInterface
     */
    protected $configurationManager;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var ProviderResolver
     */
    protected $providerResolver;

    /**
     * @var WorkspacesAwareRecordService
     */
    protected $recordService;

    /**
     * @var ResourceFactory
     */
    protected $resourceFactory;

    /**
     * @codeCoverageIgnore
     * @param ConfigurationManagerInterface $configurationManager
     * @return void
     */
    public function injectConfigurationManager(ConfigurationManagerInterface $configurationManager)
    {
        $this->configurationManager = $configurationManager;
    }

    /**
     * @codeCoverageIgnore
     * @param ObjectManagerInterface $objectManager
     * @return void
     */
    public function injectObjectManager(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * @codeCoverageIgnore
     * @param ProviderResolver $providerResolver
     * @return void
     */
    public function injectProviderResolver(ProviderResolver $providerResolver)
    {
        $this->providerResolver = $providerResolver;
    }

    /**
     * @codeCoverageIgnore
     * @param WorkspacesAwareRecordService $recordService
     * @return void
     */
    public function injectRecordService(WorkspacesAwareRecordService $recordService)
    {
        $this->recordService = $recordService;
    }

    /**
     * @codeCoverageIgnore
     * @param ResourceFactory $resourceFactory
     * @return void
     */
    public function injectResourceFactory(ResourceFactory $resourceFactory)
    {
        $this->resourceFactory = $resourceFactory;
    }

    /**
     * @param array $objects
     * @param string $sortBy
     * @param string $sortDirection
     * @return array
     */
    public function sortObjectsByProperty(array $objects, $sortBy, $sortDirection = 'ASC')
    {
        $ascending = 'ASC' === strtoupper($sortDirection);
        uasort($objects, function ($a, $b) use ($sortBy, $ascending) {
            $a = ObjectAccess::getPropertyPath($a, $sortBy);
            $b = ObjectAccess::getPropertyPath($b, $sortBy);
            return $ascending ? $a <=> $b : $b <=> $a;
        });
        return $objects;
    }

    /**
     * Returns the plugin.tx_extsignature.settings array.
     * Accepts any input extension name type.
     *
     * @param string $extensionName
     * @return array
     */
    public function getSettingsForExtensionName($extensionName)
    {
        $signature = ExtensionNamingUtility::getExtensionSignature($extensionName);
        return (array) $this->getTypoScriptByPath('plugin.tx_' . $signature . '.settings');
    }

    /**
     * Gets the value/array from global TypoScript by
     * dotted path expression.
     *
     * @param string $path
     * @return array|mixed
     */
    public function getTypoScriptByPath($path)
    {
        $cache = $this->getRuntimeCache();
        $cacheId = md5($path);
        $fromCache = $cache->get($cacheId);
        if ($fromCache) {
            return $fromCache;
        }
        $all = (array) $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );
        $value = &$all;
        foreach (explode('.', $path) as $segment) {
            $value = ($value[$segment . '.'] ?? $value[$segment] ?? null);
            if ($value === null) {
                break;
            }
        }
        if (is_array($value)) {
            $value = GeneralUtility::removeDotsFromTS($value);
        }
        $cache->set($cacheId, $value);
        return $value;
    }

    /**
     * Returns the complete, global TypoScript array
     * defined in TYPO3.
     *
     * @deprecated DO NOT USE THIS METHOD! It will hinder performance - and the method will be removed later.
     * @return array
     * @codeCoverageIgnore
     */
    public function getAllTypoScript()
    {
        return GeneralUtility::removeDotsFromTS(
            (array) $this->configurationManager->getConfiguration(
                ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
            )
        );
    }

    /**
     * ResolveUtility the top-priority ConfigurationPrivider which can provide
     * a working FlexForm configuration baed on the given parameters.
     *
     * @param string $table
     * @param string|null $fieldName
     * @param array|null $row
     * @param string|null $extensionKey
     * @param string|array $interfaces
     * @return ProviderInterface|null
     */
    public function resolvePrimaryConfigurationProvider(
        $table,
        $fieldName,
        array $row = null,
        $extensionKey = null,
        $interfaces = ProviderInterface::class
    ) {
        return $this->providerResolver->resolvePrimaryConfigurationProvider(
            $table,
            $fieldName,
            $row,
            $extensionKey,
            $interfaces
        );
    }

    /**
     * Resolves a ConfigurationProvider which can provide a working FlexForm
     * configuration based on the given parameters.
     *
     * @param string $table
     * @param string|null $fieldName
     * @param array $row
     * @param string $extensionKey
     * @param string|array $interfaces
     * @return ProviderInterface[]
     */
    public function resolveConfigurationProviders(
        $table,
        $fieldName,
        array $row = null,
        $extensionKey = null,
        $interfaces = ProviderInterface::class
    ) {
        return $this->providerResolver->resolveConfigurationProviders(
            $table,
            $fieldName,
            $row,
            $extensionKey,
            $interfaces
        );
    }

    /**
     * @return Resolver
     */
    public function getResolver()
    {
        return new Resolver();
    }

    /**
     * Parses the flexForm content and converts it to an array
     * The resulting array will be multi-dimensional, as a value "bla.blubb"
     * results in two levels, and a value "bla.blubb.bla" results in three levels.
     *
     * Note: multi-language flexForms are not supported yet
     *
     * @param string $flexFormContent flexForm xml string
     * @param Form $form An instance of \FluidTYPO3\Flux\Form. If transformation instructions are contained in this
     *                   configuration they are applied after conversion to array
     * @param string|null $languagePointer language pointer used in the flexForm
     * @param string|null $valuePointer value pointer used in the flexForm
     * @return array the processed array
     */
    public function convertFlexFormContentToArray(
        $flexFormContent,
        Form $form = null,
        $languagePointer = 'lDEF',
        $valuePointer = 'vDEF'
    ) {
        if (true === empty($flexFormContent)) {
            return [];
        }
        if (true === empty($languagePointer)) {
            $languagePointer = 'lDEF';
        }
        if (true === empty($valuePointer)) {
            $valuePointer = 'vDEF';
        }
        $flexFormService = $this->getFlexFormService();
        $settings = $flexFormService->convertFlexFormContentToArray($flexFormContent, $languagePointer, $valuePointer);
        if (null !== $form && $form->getOption(Form::OPTION_TRANSFORM)) {
            $transformer = $this->getFormDataTransformer();
            $settings = $transformer->transformAccordingToConfiguration($settings, $form);
        }
        return $settings;
    }

    /**
     * Returns the plugin.tx_extsignature.view array,
     * or a default set of paths if that array is not
     * defined in TypoScript.
     *
     * @deprecated See TemplatePaths object
     * @param string $extensionName
     * @return array|null
     * @codeCoverageIgnore
     */
    public function getViewConfigurationForExtensionName($extensionName)
    {
        return $this->createTemplatePaths($extensionName)->toArray();
    }

    /**
     * @param mixed $value
     * @param boolean $persistent
     * @param array ...$identifyingValues
     * @return void
     */
    public function setInCaches($value, $persistent, ...$identifyingValues)
    {
        $cacheKey = $this->createCacheIdFromValues($identifyingValues);
        $this->getRuntimeCache()->set($cacheKey, $value);
        if ($persistent) {
            $this->getPersistentCache()->set($cacheKey, $value);
        }
    }

    /**
     * @param array ...$identifyingValues
     * @return mixed|false
     */
    public function getFromCaches(...$identifyingValues)
    {
        $cacheKey = $this->createCacheIdFromValues($identifyingValues);
        return $this->getRuntimeCache()->get($cacheKey) ?: $this->getPersistentCache()->get($cacheKey);
    }

    /**
     * @param string $reference
     * @return string
     */
    public function convertFileReferenceToTemplatePathAndFilename($reference)
    {
        $parts = explode(':', $reference);
        $filename = array_pop($parts);
        if (true === ctype_digit($filename)) {
            /** @var File $file */
            $file = $this->resourceFactory->getFileObjectFromCombinedIdentifier($reference);
            return $file->getIdentifier();
        }
        $reference = $this->resolveAbsolutePathForFilename($reference);
        return $reference;
    }

    /**
     * @param string $reference
     * @return array
     */
    public function getViewConfigurationByFileReference($reference)
    {
        $extensionKey = 'flux';
        if (0 === strpos($reference, 'EXT:')) {
            $extensionKey = substr($reference, 4, strpos($reference, '/') - 4);
        }
        $templatePaths = $this->createTemplatePaths($extensionKey);
        return $templatePaths->toArray();
    }

    /**
     * Get definitions of paths for Page Templates defined in TypoScript
     *
     * @param string $extensionName
     * @return array
     * @api
     */
    public function getPageConfiguration($extensionName = null)
    {
        if (null !== $extensionName && true === empty($extensionName)) {
            // Note: a NULL extensionName means "fetch ALL defined collections" whereas
            // an empty value that is not null indicates an incorrect caller. Instead
            // of returning ALL paths here, an empty array is the proper return value.
            // However, dispatch a debug message to inform integrators of the problem.
            $this->getLogger()->log(
                'notice',
                'Template paths have been attempted fetched using an empty value that is NOT NULL in ' .
                get_class($this) . '. This indicates a potential problem with your TypoScript configuration - a ' .
                'value which is expected to be an array may be defined as a string. This error is not fatal but may ' .
                'prevent the affected collection (which cannot be identified here) from showing up'
            );
            return [];
        }

        $plugAndPlayEnabled = ExtensionConfigurationUtility::getOption(
            ExtensionConfigurationUtility::OPTION_PLUG_AND_PLAY
        );
        $plugAndPlayDirectory = ExtensionConfigurationUtility::getOption(
            ExtensionConfigurationUtility::OPTION_PLUG_AND_PLAY_DIRECTORY
        );
        if (!is_scalar($plugAndPlayDirectory)) {
            return [];
        }
        $plugAndPlayTemplatesDirectory = trim((string) $plugAndPlayDirectory, '/.') . '/';
        if ($plugAndPlayEnabled && $extensionName === 'Flux') {
            return [
                TemplatePaths::CONFIG_TEMPLATEROOTPATHS => [
                    $plugAndPlayTemplatesDirectory
                    . DropInContentTypeDefinition::TEMPLATES_DIRECTORY
                    . DropInContentTypeDefinition::PAGE_DIRECTORY
                ],
                TemplatePaths::CONFIG_PARTIALROOTPATHS => [
                    $plugAndPlayTemplatesDirectory . DropInContentTypeDefinition::PARTIALS_DIRECTORY
                ],
                TemplatePaths::CONFIG_LAYOUTROOTPATHS => [
                    $plugAndPlayTemplatesDirectory . DropInContentTypeDefinition::LAYOUTS_DIRECTORY
                ],
            ];
        }
        if (null !== $extensionName) {
            $templatePaths = $this->createTemplatePaths($extensionName);
            return $templatePaths->toArray();
        }
        $configurations = [];
        $registeredExtensionKeys = Core::getRegisteredProviderExtensionKeys('Page');
        foreach ($registeredExtensionKeys as $registeredExtensionKey) {
            $templatePaths = $this->createTemplatePaths($registeredExtensionKey);
            $configurations[$registeredExtensionKey] = $templatePaths->toArray();
        }
        if ($plugAndPlayEnabled) {
            $configurations['FluidTYPO3.Flux'] = array_replace(
                $configurations['FluidTYPO3.Flux'] ?? [],
                [
                    TemplatePaths::CONFIG_TEMPLATEROOTPATHS => [
                        $plugAndPlayTemplatesDirectory
                        . DropInContentTypeDefinition::TEMPLATES_DIRECTORY
                        . DropInContentTypeDefinition::PAGE_DIRECTORY
                    ],
                    TemplatePaths::CONFIG_PARTIALROOTPATHS => [
                        $plugAndPlayTemplatesDirectory . DropInContentTypeDefinition::PARTIALS_DIRECTORY
                    ],
                    TemplatePaths::CONFIG_LAYOUTROOTPATHS => [
                        $plugAndPlayTemplatesDirectory . DropInContentTypeDefinition::LAYOUTS_DIRECTORY
                    ],
                ]
            );
        }
        return $configurations;
    }

    /**
     * Resolve fluidpages specific configuration provider. Always
     * returns the main PageProvider type which needs to be used
     * as primary PageProvider when processing a complete page
     * rather than just the "sub configuration" field value.
     *
     * @param array $row
     * @return ProviderInterface|NULL
     */
    public function resolvePageProvider($row)
    {
        $provider = $this->resolvePrimaryConfigurationProvider('pages', PageProvider::FIELD_NAME_MAIN, $row);
        return $provider;
    }

    /**
     * @param array $identifyingValues
     * @return string
     */
    protected function createCacheIdFromValues(array $identifyingValues)
    {
        return 'flux-' . md5(serialize($identifyingValues));
    }

    /**
     * @return FrontendInterface
     * @codeCoverageIgnore
     */
    protected function getRuntimeCache()
    {
        static $cache;
        if (!$cache) {
            /** @var CacheManager $cacheManager */
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            $cache = $cacheManager->getCache('cache_runtime');
        }
        return $cache;
    }

    /**
     * @return FrontendInterface
     * @codeCoverageIgnore
     */
    protected function getPersistentCache()
    {
        static $cache;
        if (!$cache) {
            /** @var CacheManager $cacheManager */
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            try {
                $cache = $cacheManager->getCache('flux');
            } catch (NoSuchCacheException $error) {
                $cache = $cacheManager->getCache('cache_runtime');
            }
        }
        return $cache;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function createTemplatePaths(string $registeredExtensionKey): TemplatePaths
    {
        /** @var TemplatePaths $templatePaths */
        $templatePaths = GeneralUtility::makeInstance(
            TemplatePaths::class,
            ExtensionNamingUtility::getExtensionKey($registeredExtensionKey)
        );
        return $templatePaths;
    }

    /**
     * @return FormDataTransformer
     * @codeCoverageIgnore
     */
    protected function getFormDataTransformer()
    {
        /** @var FormDataTransformer $transformer */
        $transformer = $this->objectManager->get(FormDataTransformer::class);
        return $transformer;
    }

    /**
     * @return FlexFormService|\TYPO3\CMS\Extbase\Service\FlexFormService
     * @codeCoverageIgnore
     */
    protected function getFlexFormService()
    {
        /** @var class-string $serviceClassName */
        $serviceClassName = class_exists(FlexFormService::class)
            ? FlexFormService::class
            : \TYPO3\CMS\Extbase\Service\FlexFormService::class;
        /** @var FlexFormService|\TYPO3\CMS\Extbase\Service\FlexFormService $flexFormService */
        $flexFormService = $this->objectManager->get($serviceClassName);
        return $flexFormService;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function getLogger(): LoggerInterface
    {
        /** @var LogManager $logManager */
        $logManager = GeneralUtility::makeInstance(LogManager::class);
        return $logManager->getLogger(__CLASS__);
    }

    /**
     * @codeCoverageIgnore
     */
    protected function resolveAbsolutePathForFilename(string $filename): string
    {
        return GeneralUtility::getFileAbsFileName($filename);
    }
}
