<?php
namespace FluidTYPO3\Flux;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Form\Container\Sheet;
use FluidTYPO3\Flux\Form\ContainerInterface;
use FluidTYPO3\Flux\Form\FormInterface;
use FluidTYPO3\Flux\Outlet\OutletInterface;
use FluidTYPO3\Flux\Outlet\StandardOutlet;
use FluidTYPO3\Flux\Package\FluxPackageFactory;
use FluidTYPO3\Flux\Utility\ExtensionNamingUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;

/**
 * Form
 */
class Form extends Form\AbstractFormContainer implements Form\FieldContainerInterface
{

    const OPTION_STATIC = 'static';
    const OPTION_SORTING = 'sorting';
    const OPTION_GROUP = 'group';
    const OPTION_ICON = 'icon';
    const OPTION_TCA_LABELS = 'labels';
    const OPTION_TCA_HIDE = 'hide';
    const OPTION_TCA_START = 'start';
    const OPTION_TCA_END = 'end';
    const OPTION_TCA_DELETE = 'delete';
    const OPTION_TCA_FEGROUP = 'frontendUserGroup';
    const OPTION_TEMPLATEFILE = 'templateFile';
    const OPTION_RECORD = 'record';
    const OPTION_RECORD_FIELD = 'recordField';
    const OPTION_RECORD_TABLE = 'recordTable';
    const OPTION_DEFAULT_VALUES = 'defaultValues';
    const POSITION_TOP = 'top';
    const POSITION_BOTTOM = 'bottom';
    const POSITION_BOTH = 'both';
    const POSITION_NONE = 'none';
    const CONTROL_INFO = 'info';
    const CONTROL_NEW = 'new';
    const CONTROL_DRAGDROP = 'dragdrop';
    const CONTROL_SORT = 'sort';
    const CONTROL_HIDE = 'hide';
    const CONTROL_DELETE = 'delete';
    const CONTROL_LOCALISE = 'localize';
    const DEFAULT_LANGUAGEFILE = '/Resources/Private/Language/locallang.xlf';

    /**
     * Machine-readable, lowerCamelCase ID of this form. DOM compatible.
     *
     * @var string
     */
    protected $id;

    /**
     * Should be set to contain the extension name in UpperCamelCase of
     * the extension implementing this form object.
     *
     * @var string
     */
    protected $extensionName;

    /**
     * If TRUE, removes sheet wrappers if there is only a single sheet.
     *
     * @var boolean
     */
    protected $compact = false;

    /**
     * @var string
     */
    protected $description;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var OutletInterface
     */
    protected $outlet;

    /**
     * @return void
     */
    public function initializeObject()
    {
        /** @var Form\Container\Sheet $defaultSheet */
        $defaultSheet = $this->getObjectManager()->get(Sheet::class);
        $defaultSheet->setName('options');
        $defaultSheet->setLabel('LLL:EXT:flux' . $this->localLanguageFileRelativePath . ':tt_content.tx_flux_options');
        $this->add($defaultSheet);
        $this->outlet = $this->getObjectManager()->get(StandardOutlet::class);
    }

    /**
     * @param array $settings
     * @return FormInterface
     */
    public static function create(array $settings = [])
    {
        /** @var ObjectManagerInterface $objectManager */
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        if (isset($settings['extensionName'])) {
            $className = FluxPackageFactory::getPackageWithFallback($settings['extensionName'])
                ->getImplementation(FluxPackage::IMPLEMENTATION_FORM);
        } else {
            $className = get_called_class();
        }
        /** @var FormInterface $object */
        $object = $objectManager->get($className);
        return $object->modify($settings);
    }

    /**
     * @param Form\FormInterface $child
     * @return Form\FormInterface
     */
    public function add(Form\FormInterface $child)
    {
        if (false === $child instanceof Form\Container\Sheet) {
            /** @var Form\Container\Sheet $last */
            $last = $this->last();
            $last->add($child);
        } else {
            $children = $this->children;
            foreach ($children as $existingChild) {
                if ($child->getName() === $existingChild->getName()) {
                    return $this;
                }
            }
            $this->children->attach($child);
            $child->setParent($this);
        }
        return $this;
    }

    /**
     * @return array
     */
    public function build()
    {
        $disableLocalisation = 1;
        $inheritLocalisation = 0;
        $dataStructArray = [
            'meta' => [
                'langDisable' => $disableLocalisation,
                'langChildren' => $inheritLocalisation
            ],
        ];
        $copy = clone $this;
        foreach ($this->getSheets(true) as $sheet) {
            if (false === $sheet->hasChildren()) {
                $copy->remove($sheet->getName());
            }
        }
        $sheets = $copy->getSheets();
        $compactExtensionToggleOn = 0 < $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['flux']['setup']['compact'];
        $compactConfigurationToggleOn = 0 < $copy->getCompact();
        if (($compactExtensionToggleOn || $compactConfigurationToggleOn) && 1 === count($sheets)) {
            $dataStructArray = $copy->last()->build();
            $dataStructArray['meta'] = ['langDisable' => $disableLocalisation];
            unset($dataStructArray['ROOT']['TCEforms']);
        } elseif (0 < count($sheets)) {
            $dataStructArray['sheets'] = $copy->buildChildren($this->children);
        } else {
            $dataStructArray['ROOT'] = [
                'type' => 'array',
                'el' => []
            ];
        }
        return $dataStructArray;
    }

    /**
     * @param boolean $includeEmpty
     * @return Form\Container\Sheet[]
     */
    public function getSheets($includeEmpty = false)
    {
        $sheets = [];
        foreach ($this->children as $sheet) {
            if (false === $sheet->hasChildren() && false === $includeEmpty) {
                continue;
            }
            $name = $sheet->getName();
            $sheets[$name] = $sheet;
        }
        return $sheets;
    }

    /**
     * @return Form\FieldInterface[]
     */
    public function getFields()
    {
        $fields = [];
        foreach ($this->getSheets() as $sheet) {
            $fieldsInSheet = $sheet->getFields();
            $fields = array_merge($fields, $fieldsInSheet);
        }
        return $fields;
    }

    /**
     * @param boolean $compact
     * @return Form\FormInterface
     */
    public function setCompact($compact)
    {
        $this->compact = $compact;
        return $this;
    }

    /**
     * @return boolean
     */
    public function getCompact()
    {
        return $this->compact;
    }

    /**
     * @param string $extensionName
     * @return Form\FormInterface
     */
    public function setExtensionName($extensionName)
    {
        $this->extensionName = $extensionName;
        return $this;
    }

    /**
     * @return string
     */
    public function getExtensionName()
    {
        return $this->extensionName;
    }

    /**
     * @param string $group
     * @return Form\FormInterface
     */
    public function setGroup($group)
    {
        GeneralUtility::logDeprecatedFunction();
        $this->setOption(self::OPTION_GROUP, $group);
        return $this;
    }

    /**
     * @return string
     */
    public function getGroup()
    {
        GeneralUtility::logDeprecatedFunction();
        return $this->getOption(self::OPTION_GROUP);
    }

    /**
     * @param string $id
     * @return Form\FormInterface
     */
    public function setId($id)
    {
        $allowed = 'a-z0-9_';
        $pattern = '/[^' . $allowed . ']+/i';
        if (preg_match($pattern, $id)) {
            $this->getConfigurationService()->message(
                'Flux FlexForm with id "' . $id . '" uses invalid characters in the ID; valid characters are: "' .
                $allowed . '" and the pattern used for matching is "' . $pattern . '". This bad ID name will prevent ' .
                'you from utilising some features, fx automatic LLL reference building, but is not fatal',
                GeneralUtility::SYSLOG_SEVERITY_NOTICE
            );
        }
        $this->id = $id;
        if (true === empty($this->name)) {
            $this->name = $id;
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $description
     * @return Form\FormInterface
     */
    public function setDescription($description)
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        $description = $this->description;
        $translated = null;
        $extensionKey = ExtensionNamingUtility::getExtensionKey($this->extensionName);
        if (true === empty($description)) {
            $relativeFilePath = $this->getLocalLanguageFileRelativePath();
            $relativeFilePath = ltrim($relativeFilePath, '/');
            $filePrefix = 'LLL:EXT:' . $extensionKey . '/' . $relativeFilePath;
            $description = $filePrefix . ':' . trim('flux.' . $this->id . '.description');
        }
        return $description;
    }

    /**
     * @param array $options
     * @return Form\FormInterface
     */
    public function setOptions(array $options)
    {
        $this->options = $options;
        return $this;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return Form\FormInterface
     */
    public function setOption($name, $value)
    {
        if (strpos($name, '.') === false) {
            $this->options[$name] = $value;
        } else {
            $subject = &$this->options;
            $segments = explode('.', $name);
            while ($segment = array_shift($segments)) {
                if (isset($subject[$segment])) {
                    $subject = &$subject[$segment];
                } elseif (count($segments) === 0) {
                    $subject = $value;
                } else {
                    $subject[$segment] = [];
                    $subject = &$subject[$segment];
                }
            }
        }

        return $this;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function getOption($name)
    {
        return ObjectAccess::getPropertyPath($this->options, $name);
    }

    /**
     * @param string $name
     * @return boolean
     */
    public function hasOption($name)
    {
        return true === isset($this->options[$name]);
    }

    /**
     * @return boolean
     */
    public function hasChildren()
    {
        foreach ($this->children as $child) {
            if (true === $child->hasChildren()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param OutletInterface $outlet
     * @return Form\FormInterface
     */
    public function setOutlet(OutletInterface $outlet)
    {
        $this->outlet = $outlet;
        return $this;
    }

    /**
     * @return OutletInterface
     */
    public function getOutlet()
    {
        return $this->outlet;
    }

    /**
     * @param array $structure
     * @return ContainerInterface
     */
    public function modify(array $structure)
    {
        if (true === isset($structure['options']) && true === is_array($structure['options'])) {
            foreach ($structure['options'] as $name => $value) {
                $this->setOption($name, $value);
            }
            unset($structure['options']);
        }
        if (true === isset($structure['sheets'])) {
            foreach ((array) $structure['sheets'] as $index => $sheetData) {
                $sheetName = true === isset($sheetData['name']) ? $sheetData['name'] : $index;
                // check if field already exists - if it does, modify it. If it does not, create it.
                if (true === $this->has($sheetName)) {
                    $sheet = $this->get($sheetName);
                } else {
                    $sheet = $this->createContainer('Sheet', $sheetName);
                }
                $sheet->modify($sheetData);
            }
        }
        return parent::modify($structure);
    }
}
