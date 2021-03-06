<?php

/**
 * This file is part of MultiColumnWizard.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    MultiColumnWizard
 * @author     Stefan Heimes <stefan_heimes@hotmail.com>
 * @copyright  Andreas Schempp 2011
 * @copyright  certo web & design GmbH 2011
 * @copyright  MEN AT WORK 2013
 * @license    LGPL
 */

namespace MenAtWork\MultiColumnWizardBundle\Contao\Events;

use Contao\Config;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\Database;
use Contao\DataContainer;
use Contao\Dbafs;
use Contao\Input;
use Contao\PageTree;
use Contao\Session;
use Contao\StringUtil;
use Contao\System;
use ContaoCommunityAlliance\DcGeneral\Contao\Compatibility\DcCompat;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\ContaoWidgetManager;
use FilesModel;
use FileTree;
use MenAtWork\MultiColumnWizardBundle\Contao\Widgets\MultiColumnWizard;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Class ExecutePostActions
 *
 * @package MenAtWork\MultiColumnWizardBundle\Contao\Events
 */
class ExecutePostActions extends BaseListener
{
    /**
     * Create a new row.
     *
     * @param string        $action The action.
     *
     * @param DataContainer $dc     The current context.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function handleRowCreation($action, $dc)
    {
        // Check the context.
        if ('mcwCreateNewRow' != $action) {
            return;
        }

        if ($dc instanceof DcCompat) {
            /** @var  DcCompat $dcGeneral */
            $dcGeneral = $dc;

            // Get the field name, handel editAll as well.
            $fieldName = Input::post('name');
            if (Input::get('act') == 'editAll') {
                $fieldName = \preg_replace('/(.*)_[0-9a-zA-Z]+$/', '$1', $fieldName);
            }

            // Trigger the dcg to generate the data.
            $env = $dcGeneral->getEnvironment();
            $model = $dcGeneral
                ->getEnvironment()
                ->getDataProvider()
                ->getEmptyModel();

            $dcgContaoWidgetManager = new ContaoWidgetManager($env, $model);
            /** @var MultiColumnWizard $widget */
            $widget                 = $dcgContaoWidgetManager->getWidget($fieldName);

            // The field does not exist
            if (empty($widget)) {
                $this->log('Field "' . $fieldName . '" does not exist in definition "' . $dc->table . '"',
                    __METHOD__,
                    TL_ERROR
                );
                throw new BadRequestHttpException('Bad request');
            }

            // Get the max row count or preset it.
            $maxRowCount = Input::post('maxRowId');
            if (empty($maxRowCount)) {
                $maxRowCount = 0;
            }

            throw new ResponseException($this->convertToResponse($widget->generate(($maxRowCount + 1), true)));
        } else {
            // Get the field name, handel editAll as well.
            $fieldName = $dc->inputName = Input::post('name');
            if (Input::get('act') == 'editAll') {
                $fieldName = \preg_replace('/(.*)_[0-9a-zA-Z]+$/', '$1', $fieldName);
            }
            $dc->field = $fieldName;

            // The field does not exist
            if (!isset($GLOBALS['TL_DCA'][$dc->table]['fields'][$fieldName])) {
                $this->log('Field "' . $fieldName . '" does not exist in DCA "' . $dc->table . '"', __METHOD__, TL_ERROR);
                throw new BadRequestHttpException('Bad request');
            }

            /** @var string $widgetClassName */
            $widgetClassName = $GLOBALS['BE_FFL']['multiColumnWizard'];

            // Get the max row count or preset it.
            $maxRowCount = Input::post('maxRowId');
            if (empty($maxRowCount)) {
                $maxRowCount = 0;
            }

            /** @var MultiColumnWizard $widget */
            $widget = new $widgetClassName(
                $widgetClassName::getAttributesFromDca(
                    $GLOBALS['TL_DCA'][$dc->table]['fields'][$fieldName],
                    $dc->inputName,
                    '',
                    $fieldName,
                    $dc->table,
                    $dc
                )
            );

            throw new ResponseException($this->convertToResponse($widget->generate(($maxRowCount + 1), true)));
        }
    }

    /**
     * Try to rewrite the reload event. We have a tiny huge problem with the field names of the mcw and contao.
     *
     * @param string        $action
     *
     * @param DataContainer $dc
     *
     * @throws \Exception
     */
    public function executePostActions($action, DataContainer $dc)
    {
        // Kick out if the context isn't the right one.
        if ($action != 'reloadFiletree_mcw' && $action != 'reloadPagetree_mcw') {
            return;
        }

        $intId    = \Input::get('id');
        $strField = $dc->inputName = \Input::post('name');

        // Get the field name parts.
        $fieldParts = preg_split('/_row[0-9]*_/i', $strField);
        preg_match('/_row[0-9]*_/i', $strField, $arrRow);
        $intRow = substr(substr($arrRow[0], 4), 0, -1);

        // Rebuild field name.
        $mcwFieldName    = $fieldParts[0] . '[' . $intRow . '][' . $fieldParts[1] . ']';
        $mcwBaseName     = $fieldParts[0];
        $mcwSupFieldName = $fieldParts[1];

        // Handle the keys in "edit multiple" mode
        if (\Input::get('act') == 'editAll') {
            $intId    = preg_replace('/.*_([0-9a-zA-Z]+)$/', '$1', $strField);
            $strField = preg_replace('/(.*)_[0-9a-zA-Z]+$/', '$1', $strField);
        }

        $dc->field = $mcwFieldName;

        // Add the sub configuration into the DCA. We need this for contao. Without this it is not possible
        // to get the data.
        if ($GLOBALS['TL_DCA'][$dc->table]['fields'][$mcwBaseName]['inputType'] == 'multiColumnWizard') {
            $GLOBALS['TL_DCA'][$dc->table]['fields'][$dc->field] =
                $GLOBALS['TL_DCA'][$dc->table]['fields'][$mcwBaseName]['eval']['columnFields'][$mcwSupFieldName];

            $GLOBALS['TL_DCA'][$dc->table]['fields'][$strField] =
                $GLOBALS['TL_DCA'][$dc->table]['fields'][$mcwBaseName]['eval']['columnFields'][$mcwSupFieldName];
        }

        // The field does not exist
        if (!isset($GLOBALS['TL_DCA'][$dc->table]['fields'][$strField])) {
            System::log('Field "' . $strField . '" does not exist in DCA "' . $dc->table . '"', __METHOD__, TL_ERROR);
            throw new BadRequestHttpException('Bad request');
        }

        $objRow   = null;
        $varValue = null;

        // Load the value
        if (Input::get('act') != 'overrideAll') {
            if ($GLOBALS['TL_DCA'][$dc->table]['config']['dataContainer'] == 'File') {
                $varValue = Config::get($strField);
            } elseif ($intId > 0 && Database::getInstance()->tableExists($dc->table)) {
                $objRow = Database::getInstance()->prepare("SELECT * FROM " . $dc->table . " WHERE id=?")
                    ->execute($intId);

                // The record does not exist
                if ($objRow->numRows < 1) {
                    System::log('A record with the ID "' . $intId . '" does not exist in table "' . $dc->table . '"',
                        __METHOD__, TL_ERROR);
                    throw new BadRequestHttpException('Bad request');
                }

                $varValue         = $objRow->$strField;
                $dc->activeRecord = $objRow;
            }
        }

        // Call the load_callback
        if (\is_array($GLOBALS['TL_DCA'][$dc->table]['fields'][$strField]['load_callback'])) {
            foreach ($GLOBALS['TL_DCA'][$dc->table]['fields'][$strField]['load_callback'] as $callback) {
                if (\is_array($callback)) {
                    $this->import($callback[0]);
                    $varValue = $this->{$callback[0]}->{$callback[1]}($varValue, $dc);
                } elseif (\is_callable($callback)) {
                    $varValue = $callback($varValue, $dc);
                }
            }
        }

        // Set the new value
        $varValue = Input::post('value', true);
        $strKey   = ($this->strAction == 'reloadPagetree') ? 'pageTree' : 'fileTree';

        // Convert the selected values
        if ($varValue != '') {
            $varValue = StringUtil::trimsplit("\t", $varValue);

            // Automatically add resources to the DBAFS
            if ($strKey == 'fileTree') {
                foreach ($varValue as $k => $v) {
                    $v = rawurldecode($v);

                    if (Dbafs::shouldBeSynchronized($v)) {
                        $objFile = FilesModel::findByPath($v);

                        if ($objFile === null) {
                            $objFile = Dbafs::addResource($v);
                        }

                        $varValue[$k] = $objFile->uuid;
                    }
                }
            }

            $varValue = serialize($varValue);
        }

        /** @var FileTree|PageTree $strClass */
        $strClass        = $GLOBALS['BE_FFL'][$strKey];
        $fieldAttributes = $strClass::getAttributesFromDca(
            $GLOBALS['TL_DCA'][$dc->table]['fields'][$strField],
            $dc->inputName,
            $varValue,
            $strField,
            $dc->table,
            $dc
        );

        $fieldAttributes['id']       = \Input::post('name');
        $fieldAttributes['name']     = $mcwFieldName;
        $fieldAttributes['value']    = $varValue;
        $fieldAttributes['strTable'] = $dc->table;
        $fieldAttributes['strField'] = $strField;

        /** @var FileTree|PageTree $objWidget */
        $objWidget = new $strClass($fieldAttributes);

        throw new ResponseException($this->convertToResponse($objWidget->generate()));
    }
}
