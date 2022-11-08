<?php
namespace FluidTYPO3\Flux\Tests\Unit\Integration\NormalizedData;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Form;
use FluidTYPO3\Flux\Form\Transformation\FormDataTransformer;
use FluidTYPO3\Flux\Integration\HookSubscribers\PagePreviewRenderer;
use FluidTYPO3\Flux\Integration\NormalizedData\Converter\ConverterInterface;
use FluidTYPO3\Flux\Integration\NormalizedData\Converter\InlineRecordDataConverter;
use FluidTYPO3\Flux\Integration\NormalizedData\FlexFormImplementation;
use FluidTYPO3\Flux\Integration\NormalizedData\ImplementationRegistry;
use FluidTYPO3\Flux\Provider\Interfaces\FormProviderInterface;
use FluidTYPO3\Flux\Provider\Provider;
use FluidTYPO3\Flux\Provider\ProviderInterface;
use FluidTYPO3\Flux\Provider\ProviderResolver;
use FluidTYPO3\Flux\Tests\Fixtures\Classes\DummyPageController;
use FluidTYPO3\Flux\Tests\Unit\AbstractTestCase;
use FluidTYPO3\Flux\Utility\ExtensionConfigurationUtility;
use TYPO3\CMS\Backend\Controller\PageLayoutController;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class DataAccessTraitTest extends AbstractTestCase
{
    private ?FormDataTransformer $formDataTransformer;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['flux'][ExtensionConfigurationUtility::OPTION_FLEXFORM_TO_IRRE] = 1;

        $this->formDataTransformer = $this->getMockBuilder(FormDataTransformer::class)
            ->setMethods(['transformAccordingToConfiguration'])
            ->disableOriginalConstructor()
            ->getMock();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']);
    }

    public function testTraitThrowsUnexpectedValueExceptionOnMissingRecord(): void
    {
        $configurationManager = $this->getMockBuilder(ConfigurationManagerInterface::class)
            ->getMockForAbstractClass();
        $configurationManager->method('getConfiguration')->willReturn(['foo' => 'bar']);
        $configurationManager->method('getContentObject')->willReturn(null);

        $subject = new DummyPageController();

        self::expectExceptionCode(1666538343);
        $subject->injectConfigurationManager($configurationManager);
    }

    public function testTraitBehavior(): void
    {
        FlexFormImplementation::registerForTableAndField('pages', 'tx_fed_page_flexform');
        ImplementationRegistry::registerImplementation(FlexFormImplementation::class, ['foo' => 'bar']);

        $contentObject = $this->getMockBuilder(ContentObjectRenderer::class)->disableOriginalConstructor()->getMock();
        $contentObject->method('getCurrentTable')->willReturn('tt_content');
        $contentObject->data = ['uid' => 123];

        $configurationManager = $this->getMockBuilder(ConfigurationManagerInterface::class)
            ->getMockForAbstractClass();
        $configurationManager->method('getConfiguration')->willReturn(['foo' => 'bar']);
        $configurationManager->method('getContentObject')->willReturn($contentObject);

        $converter = $this->getMockBuilder(InlineRecordDataConverter::class)->disableOriginalConstructor()->getMock();
        $converter->method('convertData')->willReturnArgument(0);

        $flexFormImplementation = $this->getMockBuilder(FlexFormImplementation::class)
            ->setMethods(['getConverterForTableFieldAndRecord'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->formDataTransformer->expects(self::once())
            ->method('transformAccordingToConfiguration')
            ->willReturnArgument(0);

        $subject = new DummyPageController();

        GeneralUtility::addInstance(FlexFormImplementation::class, $flexFormImplementation);

        $subject->injectConfigurationManager($configurationManager);
    }

    protected function createObjectManagerInstance(): ObjectManagerInterface
    {
        $provider = $this->getMockBuilder(ProviderInterface::class)->getMockForAbstractClass();
        $provider->method('getForm')->willReturn(Form::create());

        $providerResolver = $this->getMockBuilder(ProviderResolver::class)
            ->setMethods(['resolvePrimaryConfigurationProvider'])
            ->disableOriginalConstructor()
            ->getMock();
        $providerResolver->method('resolvePrimaryConfigurationProvider')->willReturn($provider);

        $instance = parent::createObjectManagerInstance();
        $instance->method('get')->willReturnMap(
            [
                [ProviderResolver::class, $providerResolver],
                [FormDataTransformer::class, $this->formDataTransformer],
            ]
        );
        return $instance;
    }
}