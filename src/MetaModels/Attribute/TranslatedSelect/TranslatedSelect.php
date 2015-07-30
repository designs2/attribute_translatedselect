<?php

/**
 * The MetaModels extension allows the creation of multiple collections of custom items,
 * each with its own unique set of selectable attributes, with attribute extendability.
 * The Front-End modules allow you to build powerful listing and filtering of the
 * data in each collection.
 *
 * PHP version 5
 * @package     MetaModels
 * @subpackage  AttributeTranslatedSelect
 * @author      Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author      Christian de la Haye <service@delahaye.de>
 * @author      Andreas Isaak <info@andreas-isaak.de>
 * @author      Markus Gerards <markus.gerards@googlemail.com>
 * @author      Paul Pflugradt <paulpflugradt@googlemail.com>
 * @author      Stefan Heimes <stefan_heimes@hotmail.com>
 * @copyright   The MetaModels team.
 * @license     LGPL.
 * @filesource
 */

namespace MetaModels\Attribute\TranslatedSelect;

use MetaModels\Attribute\Select\Select;
use MetaModels\Attribute\ITranslated;

/**
 * This is the MetaModelAttribute class for handling translated select attributes.
 *
 * @package    MetaModels
 * @subpackage AttributeTranslatedSelect
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 */
class TranslatedSelect extends Select implements ITranslated
{
    /**
     * Determine the correct language column to use.
     *
     * @return string
     */
    protected function getLanguageColumn()
    {
        return $this->get('select_langcolumn');
    }

    /**
     * Determine the correct sorting table to use.
     *
     * @return string
     */
    protected function getSortingOverrideTable()
    {
        return $this->get('select_srctable') ?: false;
    }

    /**
     * Determine the correct sorting column to use.
     *
     * @return string
     */
    protected function getSortingOverrideColumn()
    {
        return $this->get('select_srcsorting') ?: 'id';
    }

    /**
     * {@inheritdoc}
     */
    public function sortIds($idList, $strDirection)
    {
        $metaModel    = $this->getMetaModel();
        $strTableName = $this->getSortingOverrideTable();
        if ($strTableName) {
            $strColNameId  = 'id';
            $strSortColumn = $this->getSortingOverrideColumn();

            return $this->getDatabase()
                ->prepare(
                    sprintf(
                        'SELECT %1$s.id FROM %1$s
                    LEFT JOIN %3$s ON (%3$s.%4$s=%1$s.%2$s)
                    WHERE %1$s.id IN (%5$s)
                    ORDER BY %3$s.%6$s %7$s',
                        // @codingStandardsIgnoreStart - we want to keep the numbers at the end of the lines below.
                        $metaModel->getTableName(), // 1
                        $this->getColName(),        // 2
                        $strTableName,              // 3
                        $strColNameId,              // 4
                        implode(',', $idList),      // 5
                        $strSortColumn,             // 6
                        $strDirection               // 7
                    // @codingStandardsIgnoreEnd
                    )
                )
                ->execute()
                ->fetchEach('id');
        }

        $addWhere = $this->getAdditionalWhere();
        $langSet  = sprintf('\'%s\',\'%s\'', $metaModel->getActiveLanguage(), $metaModel->getFallbackLanguage());
        $sorted   = $this->getDatabase()
            ->prepare(
                sprintf(
                    'SELECT %3$s.id
                        FROM %3$s
                        LEFT JOIN %1$s ON (%1$s.id = (SELECT
                            %1$s.id
                            FROM %1$s
                            WHERE %5$s IN (%6$s)
                            AND (%1$s.%2$s=%3$s.%4$s)
                            %7$s
                            ORDER BY FIELD(%1$s.%5$s,%6$s)
                            LIMIT 1
                        ))
                        WHERE %3$s.id IN (%9$s)
                        ORDER BY %1$s.%8$s %10$s',
                    // @codingStandardsIgnoreStart - we want to keep the numbers at the end of the lines below.
                    $this->getSelectSource(),                  // 1
                    $this->getIdColumn(),                      // 2
                    $this->getMetaModel()->getTableName(),     // 3
                    $this->getColName(),                       // 4
                    $this->getLanguageColumn(),                // 5
                    $langSet,                                  // 6
                    ($addWhere ? ' AND ('.$addWhere.')' : ''), // 7
                    $this->getSortingColumn(),                 // 8
                    $this->parameterMask($idList),             // 9
                    $strDirection                              // 7
                // @codingStandardsIgnoreEnd
                )
            )
            ->execute($idList)
            ->fetchEach('id');

        return $sorted;
    }

    /**
     * {@inheritdoc}
     */
    public function getAttributeSettingNames()
    {
        return array_merge(parent::getAttributeSettingNames(), array(
            'select_langcolumn', 'select_srctable', 'select_srcsorting'
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function valueToWidget($varValue)
    {
        $strColNameWhere = $this->getAdditionalWhere();
        $strColNameAlias = $this->getAliasColumn();
        if (!$strColNameAlias) {
            $strColNameAlias = $this->getIdColumn();
        }

        // Easy out, we have the correct language.
        if ($varValue[$this->getLanguageColumn()] == $this->getMetaModel()->getActiveLanguage()) {
            return $varValue[$strColNameAlias];
        }

        // Translate to current language.
        $objValue = $this->getDatabase()
            ->prepare(
                sprintf(
                    'SELECT %1$s.* FROM %1$s WHERE %2$s=? AND %3$s=?%4$s',
                    $this->getSelectSource(),
                    $this->getIdColumn(),
                    $this->getLanguageColumn(),
                    ($strColNameWhere ? ' AND ('.$strColNameWhere.')' : '')
                )
            )
            ->execute(
                $varValue[$this->getIdColumn()],
                $this->getMetaModel()->getActiveLanguage()
            );

        return $objValue->$strColNameAlias;
    }

    /**
     * {@inheritdoc}
     */
    public function widgetToValue($varValue, $itemId)
    {
        $objDB           = $this->getDatabase();
        $strColNameAlias = $this->getAliasColumn();
        $strColNameId    = $this->getIdColumn();
        $strColNameWhere = $this->getAdditionalWhere();
        $strColNameLang  = $this->getLanguageColumn();
        $strLangSet      = sprintf(
            '\'%s\',\'%s\'',
            $this->getMetaModel()->getActiveLanguage(),
            $this->getMetaModel()->getFallbackLanguage()
        );

        if (!$strColNameAlias) {
            $strColNameAlias = $strColNameId;
        }

        // Lookup the id for this value.
        $objValue = $objDB
            ->prepare(
                sprintf(
                    'SELECT %1$s.* FROM %1$s WHERE %2$s=? AND %3$s IN (%4$s)%5$s',
                    $this->getSelectSource(),
                    $strColNameAlias,
                    $strColNameLang,
                    $strLangSet,
                    ($strColNameWhere ? ' AND ('.$strColNameWhere.')' : '')
                )
            )
            ->execute($varValue);

        return $objValue->row();
    }

    /**
     * Retrieve the sorting part for the getFilterOptions() queries.
     *
     * @return string
     */
    protected function getFilterOptionsOrderBy()
    {
        if ($this->getSortingOverrideTable() && $this->getSortingOverrideColumn()) {
            return sprintf(
                'FIELD(%s.id, (SELECT GROUP_CONCAT(id ORDER BY %s) FROM %s)),',
                $this->getSelectSource(),
                $this->getSortingOverrideColumn(),
                $this->getSortingOverrideTable()
            );
        }

        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getFilterOptionsForUsedOnly($usedOnly)
    {
        $tableName   = $this->getSelectSource();
        $idColumn    = $this->getIdColumn();
        $langColumn  = $this->getLanguageColumn();
        $addWhere    = $this->getAdditionalWhere();
        $firstOrder  = $this->getFilterOptionsOrderBy();
        $secondOrder = $this->getSortingColumn();
        $langSet     = sprintf(
            '\'%s\',\'%s\'',
            $this->getMetaModel()->getActiveLanguage(),
            $this->getMetaModel()->getFallbackLanguage()
        );

        if ($usedOnly) {
            return $this
                ->getDatabase()
                ->prepare(
                    sprintf(
                        'SELECT COUNT(%1$s.%2$s) as mm_count, %1$s.*
                            FROM %3$s
                            LEFT JOIN %1$s ON (%1$s.id = (SELECT
                                %1$s.id
                                FROM %1$s
                                WHERE %5$s IN (%6$s)
                                AND (%1$s.%2$s=%3$s.%4$s)
                                %7$s
                                ORDER BY FIELD(%1$s.%5$s,%6$s)
                                LIMIT 1
                            ))
                            GROUP BY %1$s.%2$s
                            ORDER BY %9$s %8$s',
                        // @codingStandardsIgnoreStart - we want to keep the numbers at the end of the lines below.
                        $tableName,                                // 1
                        $idColumn,                                 // 2
                        $this->getMetaModel()->getTableName(),     // 3
                        $this->getColName(),                       // 4
                        $langColumn,                               // 5
                        $langSet,                                  // 6
                        ($addWhere ? ' AND ('.$addWhere.')' : ''), // 7
                        $secondOrder,                              // 8
                        $firstOrder                                // 9
                        // @codingStandardsIgnoreEnd
                    )
                )
                ->execute();
        }

        return $this
            ->getDatabase()
            ->prepare(
                sprintf(
                    'SELECT COUNT(%1$s.%2$s) as mm_count, %1$s.*
                        FROM %1$s
                        WHERE %3$s IN (%4$s)
                        %5$s
                        GROUP BY %1$s.%2$s
                        ORDER BY %7$s %6$s',
                    // @codingStandardsIgnoreStart - we want to keep the numbers at the end of the lines below.
                    $tableName,                                // 1
                    $idColumn,                                 // 2
                    $langColumn,                               // 3
                    $langSet,                                  // 4
                    ($addWhere ? ' AND ('.$addWhere.')' : ''), // 5
                    $secondOrder,                              // 6
                    $firstOrder                                // 7
                    // @codingStandardsIgnoreEnd
                )
            )
            ->execute();
    }

    /**
     * {@inheritdoc}
     *
     * Fetch filter options from foreign table.
     */
    public function getFilterOptions($idList, $usedOnly, &$arrCount = null)
    {
        if (($idList !== null) && empty($idList)) {
            return array();
        }

        $strTableName = $this->getSelectSource();
        $strColNameId = $this->getIdColumn();

        if (!($strTableName && $strColNameId)) {
            return array();
        }

        if ($idList) {
            $strColNameWhere = $this->getAdditionalWhere();
            $strLangSet      = sprintf(
                '\'%s\',\'%s\'',
                $this->getMetaModel()->getActiveLanguage(),
                $this->getMetaModel()->getFallbackLanguage()
            );

            $objValue = $this
                ->getDatabase()
                ->prepare(
                    sprintf(
                        'SELECT COUNT(%1$s.%2$s) as mm_count, %1$s.*
                        FROM %3$s
                        LEFT JOIN %1$s ON (%1$s.id = (SELECT
                            %1$s.id
                            FROM %1$s
                            WHERE %7$s IN (%8$s)
                            AND (%1$s.%2$s=%3$s.%4$s)
                            %6$s
                            ORDER BY FIELD(%1$s.%7$s,%8$s)
                            LIMIT 1
                        ))
                        WHERE %3$s.id IN (%5$s)
                        GROUP BY %1$s.%2$s
                        ORDER BY %10$s %9$s',
                        // @codingStandardsIgnoreStart - we want to keep the numbers at the end of the lines below.
                        $strTableName,                                               // 1
                        $strColNameId,                                               // 2
                        $this->getMetaModel()->getTableName(),                       // 3
                        $this->getColName(),                                         // 4
                        implode(',', $idList),                                       // 5
                        ($strColNameWhere ? ' AND (' . $strColNameWhere . ')' : ''), // 6
                        $this->getLanguageColumn(),                                  // 7
                        $strLangSet,                                                 // 8
                        $this->getSortingColumn(),                                   // 9
                        $this->getFilterOptionsOrderBy()                             // 10
                        // @codingStandardsIgnoreEnd
                    )
                )
                ->execute($this->get('id'));
        } else {
            $objValue = $this->getFilterOptionsForUsedOnly($usedOnly);
        }

        return $this->convertOptionsList($objValue, $this->getAliasColumn(), $this->getValueColumn(), $arrCount);
    }

    /**
     * {@inheritdoc}
     *
     * Search the attribute in the current language.
     */
    public function searchFor($strPattern)
    {
        return $this->searchForInLanguages($strPattern, array($this->getMetaModel()->getActiveLanguage()));
    }

    /**
     * {@inheritdoc}
     */
    public function getDataFor($arrIds)
    {
        $strActiveLanguage   = $this->getMetaModel()->getActiveLanguage();
        $strFallbackLanguage = $this->getMetaModel()->getFallbackLanguage();

        $arrReturn = $this->getTranslatedDataFor($arrIds, $strActiveLanguage);

        // Second round, fetch fallback languages if not all items could be resolved.
        if ((count($arrReturn) < count($arrIds)) && ($strActiveLanguage != $strFallbackLanguage)) {
            $arrFallbackIds = array();
            foreach ($arrIds as $intId) {
                if (empty($arrReturn[$intId])) {
                    $arrFallbackIds[] = $intId;
                }
            }

            if ($arrFallbackIds) {
                $arrFallbackData = $this->getTranslatedDataFor($arrFallbackIds, $strFallbackLanguage);
                // Cannot use array_merge here as it would renumber the keys.
                foreach ($arrFallbackData as $intId => $arrValue) {
                    $arrReturn[$intId] = $arrValue;
                }
            }
        }
        return $arrReturn;
    }

    /**
     * {@inheritdoc}
     */
    public function setDataFor($arrValues)
    {
        $this->setTranslatedDataFor($arrValues, $this->getMetaModel()->getActiveLanguage());
    }

    /**
     * {@inheritdoc}
     *
     * Search the attribute in the given languages.
     */
    public function searchForInLanguages($strPattern, $arrLanguages = array())
    {
        $objDB              = $this->getDatabase();
        $strTableNameId     = $this->getSelectSource();
        $strColNameId       = $this->getIdColumn();
        $strColNameLangCode = $this->getLanguageColumn();
        $strColValue        = $this->getValueColumn();
        $strColAlias        = $this->getAliasColumn();
        $strColNameWhere    = $this->getAdditionalWhere();
        $arrReturn          = array();

        if ($strTableNameId && $strColNameId) {
            $strMetaModelTableName   = $this->getMetaModel()->getTableName();
            $strMetaModelTableNameId = $strMetaModelTableName.'_id';

            $strPattern = str_replace(array('*', '?'), array('%', '_'), $strPattern);

            // Using aliased join here to resolve issue #3 for normal select attributes
            // (SQL error for self referencing table).
            $objValue = $objDB->prepare(sprintf(
                'SELECT sourceTable.*, %2$s.id AS %3$s
                FROM %1$s sourceTable
                RIGHT JOIN %2$s ON (sourceTable.%4$s=%2$s.%5$s)
                WHERE '.($arrLanguages ? '(sourceTable.%6$s IN (%7$s))' : '').'
                AND (sourceTable.%8$s LIKE ? OR sourceTable.%9$s LIKE ?) %10$s',
                // @codingStandardsIgnoreStart - we want to keep the numbers at the end of the lines below.
                $strTableNameId,                                      // 1
                $strMetaModelTableName,                               // 2
                $strMetaModelTableNameId,                             // 3
                $strColNameId,                                        // 4
                $this->getColName(),                                  // 5
                $strColNameLangCode,                                  // 6
                '\'' . implode('\',\'', $arrLanguages) . '\'',        // 7
                $strColValue,                                         // 8
                $strColAlias,                                         // 9
                ($strColNameWhere ? ('AND ' . $strColNameWhere) : '') // 10
            // @codingStandardsIgnoreEnd
            ))
            ->execute($strPattern, $strPattern);

            while ($objValue->next()) {
                $arrReturn[] = $objValue->$strMetaModelTableNameId;
            }
        }
        return $arrReturn;
    }

    /**
     * {@inheritdoc}
     */
    public function setTranslatedDataFor($arrValues, $strLangCode)
    {
        $strMetaModelTableName = $this->getMetaModel()->getTableName();
        $strTableName          = $this->getSelectSource();
        $strColNameId          = $this->getIdColumn();

        if ($strTableName && $strColNameId) {
            $objDB    = $this->getDatabase();
            $strQuery = sprintf(
                'UPDATE %1$s SET %2$s=? WHERE %1$s.id=?',
                $strMetaModelTableName,
                $this->getColName()
            );

            foreach ($arrValues as $intItemId => $arrValue) {
                $objDB->prepare($strQuery)->execute($arrValue[$strColNameId], $intItemId);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getTranslatedDataFor($arrIds, $strLangCode)
    {
        $objDB              = $this->getDatabase();
        $strTableNameId     = $this->getSelectSource();
        $strColNameId       = $this->getIdColumn();
        $strColNameLangCode = $this->getLanguageColumn();
        $strColNameWhere    = $this->getAdditionalWhere();
        $arrReturn          = array();

        if ($strTableNameId && $strColNameId) {
            $strMetaModelTableName   = $this->getMetaModel()->getTableName();
            $strMetaModelTableNameId = $strMetaModelTableName.'_id';

            // Using aliased join here to resolve issue #3 for normal select attributes
            // (SQL error for self referencing table).
            $objValue = $objDB->prepare(sprintf(
                'SELECT sourceTable.*, %2$s.id AS %3$s
                FROM %1$s sourceTable
                LEFT JOIN %2$s
                    ON ((sourceTable.%7$s=?) AND (sourceTable.%4$s=%2$s.%5$s))
                WHERE %2$s.id IN (%6$s) %8$s',
                // @codingStandardsIgnoreStart - we want to keep the numbers at the end of the lines below.
                $strTableNameId,                                        // 1
                $strMetaModelTableName,                                 // 2
                $strMetaModelTableNameId,                               // 3
                $strColNameId,                                          // 4
                $this->getColName(),                                    // 5
                implode(',', $arrIds),                                  // 6
                $strColNameLangCode,                                    // 7
                ($strColNameWhere ? ' AND ('.$strColNameWhere.')' : '') // 8
            // @codingStandardsIgnoreEnd
            ))
                ->execute($strLangCode);
            while ($objValue->next()) {
                $arrReturn[$objValue->$strMetaModelTableNameId] = $objValue->row();
            }
        }
        return $arrReturn;
    }

    /**
     * {@inheritdoc}
     */
    public function unsetValueFor($arrIds, $strLangCode)
    {
        parent::unsetDataFor($arrIds);
    }
}
