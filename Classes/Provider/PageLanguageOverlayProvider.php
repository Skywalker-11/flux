<?php
namespace FluidTYPO3\Flux\Provider;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

/**
 * Page LanguageOverlayConfiguration Provider
 *
 * This Provider takes care of page Configuration
 * for other languages inside the pages_language_overlay
 * record.
 */
class PageLanguageOverlayProvider extends PageProvider implements ProviderInterface
{

    /**
     * @var string
     */
    protected $tableName = 'pages_language_overlay';

    /**
     * @param array $record
     * @return array
     */
    protected function loadRecordTreeFromDatabase($record)
    {
        $parentFieldName = $this->getParentFieldName($record);
        if (false === isset($record[$parentFieldName])) {
            $record[$parentFieldName] = $this->getParentFieldValue($record);
        }
        $pageRecord = $this->recordService->getSingle('pages', '*', $record['pid']);
        if ($pageRecord === null) {
            return [];
        }
        $records = [];
        while ($parentFieldName !== null && $pageRecord !== null && 0 < ($pageRecord[$parentFieldName] ?? null)) {
            $parentTranslations = $this->recordService->get(
                $this->tableName,
                '*',
                'pid = ' . $pageRecord['pid'] . ' AND sys_language_uid = ' . $record['sys_language_uid']
            );
            if (empty($parentTranslations)) {
                break;
            }
            $record = reset($parentTranslations);
            $parentFieldName = $this->getParentFieldName($record);
            $records[] = $record;
            $pageRecord = $this->recordService->getSingle('pages', '*', $pageRecord['pid']);
        }
        $records = array_reverse($records);
        return $records;
    }

    /**
     * @param array $row
     * @return string
     */
    public function getControllerActionReferenceFromRecord(array $row)
    {
        $pageRow = (array) $this->recordService->getSingle('pages', '*', $row['pid']);
        return parent::getControllerActionReferenceFromRecord($pageRow);
    }
}
