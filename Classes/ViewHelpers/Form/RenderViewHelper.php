<?php
namespace FluidTYPO3\Flux\ViewHelpers\Form;

/*
 * This file is part of the FluidTYPO3/Fluidbackend project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Form;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * ## Main form rendering ViewHelper
 *
 * Use to render a Flux form as HTML.
 */
class RenderViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    /**
     * @var boolean
     */
    protected $escapeOutput = false;

    /**
     * @return void
     */
    public function initializeArguments()
    {
        $this->registerArgument('form', Form::class, 'Form instance to render as HTML', true);
    }

    /**
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     * @return string
     */
    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext
    ) {
        $form = $arguments['form'];
        $record = $form->getOption(Form::OPTION_RECORD);
        $table = $form->getOption(Form::OPTION_RECORD_TABLE);
        $field = $form->getOption(Form::OPTION_RECORD_FIELD);
        $node = static::getNodeFactory()->create([
            'type' => 'flex',
            'renderType' => 'flex',
            'flexFormDataStructureArray' => $form->build(),
            'tableName' => $table,
            'fieldName' => $field,
            'databaseRow' => $record,
            'inlineStructure' => [],
            'parameterArray' => [
                'itemFormElName' => sprintf('data[%s][%d][%s]', $table, (integer) $record['uid'], $field),
                'itemFormElValue' => static::convertXmlToArray($record[$field]),
                'fieldChangeFunc' => [],
                'fieldConf' => [
                    'config' => [
                        'ds' => $form->build(),
                    ],
                ],
            ],
        ]);
        $output = $node->render();
        return $output['html'] ?? '';
    }

    /**
     * @codeCoverageIgnore
     */
    protected static function convertXmlToArray(string $xml): array
    {
        $array = GeneralUtility::xml2array($xml);
        if (is_array($array)) {
            return $array;
        }
        return [];
    }

    /**
     * @codeCoverageIgnore
     */
    protected static function getNodeFactory(): NodeFactory
    {
        /** @var NodeFactory $nodeFactory */
        $nodeFactory = GeneralUtility::makeInstance(NodeFactory::class);
        return $nodeFactory;
    }
}
