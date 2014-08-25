<?php
namespace TYPO3\CMS\Vidi\ViewHelpers\Grid;

/**
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Backend\Utility\IconUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3\CMS\Vidi\Domain\Repository\ContentRepositoryFactory;
use TYPO3\CMS\Vidi\Domain\Model\Content;
use TYPO3\CMS\Vidi\Formatter\FormatterInterface;
use TYPO3\CMS\Vidi\Tca\TcaService;

/**
 * View helper for rendering a row of a content object.
 */
class RowViewHelper extends AbstractViewHelper {

	/**
	 * @var array
	 */
	protected $columns = array();

	/**
	 * @param array $columns
	 */
	public function __construct($columns = array()){
		$this->columns = $columns;
	}

	/**
	 * Render a row to be displayed in the Grid given an Content Object.
	 *
	 * @param \TYPO3\CMS\Vidi\Domain\Model\Content $object
	 * @param int $index
	 * @return array
	 */
	public function render(Content $object, $index) {

		// Initialize returned array
		$output = array();

		foreach(TcaService::grid()->getFields() as $fieldNameAndPath => $configuration) {

			$fieldName = $this->getFieldPathResolver()->stripFieldPath($fieldNameAndPath);

			if (TcaService::grid()->isSystem($fieldNameAndPath)) {

				$systemFieldName = substr($fieldNameAndPath, 2);
				$className = sprintf('TYPO3\CMS\Vidi\View\System\%sSystem', ucfirst($systemFieldName));
				if (class_exists($className)) {

					/** @var AbstractViewHelper $systemColumnViewHelper */
					$systemColumnViewHelper = $this->objectManager->get($className);
					$output[$fieldName] = $systemColumnViewHelper->render($object, $index);
				}
			} elseif (!in_array($fieldNameAndPath, $this->columns) && !TcaService::grid()->isForce($fieldNameAndPath)) {

				// For performance sake, show nothing if the column is not required.
				$output[$fieldName] = '';
			} else {

				// Fetch value
				if (TcaService::grid()->hasRenderers($fieldNameAndPath)) {

					$value = '';
					$renderers = TcaService::grid()->getRenderers($fieldNameAndPath);

					// if is relation has one
					foreach ($renderers as $rendererClassName => $rendererConfiguration) {

						/** @var $rendererObject \TYPO3\CMS\Vidi\Grid\GridRendererInterface */
						$rendererObject = GeneralUtility::makeInstance($rendererClassName);
						$value .= $rendererObject
							->setObject($object)
							->setFieldName($fieldNameAndPath)
							->setFieldConfiguration($configuration)
							->setGridRendererConfiguration($rendererConfiguration)
							->render();
					}
				} else {
					$value = $this->resolveValue($object, $fieldNameAndPath);
					$value = $this->processValue($value, $object, $fieldNameAndPath); // post resolve processing.
				}

				$value = $this->formatValue($value, $configuration);
				$value = $this->wrapValue($value, $configuration);
				$value = $this->prependSpriteIcon($value, $object, $fieldNameAndPath);

				$output[$fieldName] = $value;
			}
		}

		$output['DT_RowId'] = 'row-' . $object->getUid();
		$output['DT_RowClass'] = sprintf('%s_%s', $object->getDataType(), $object->getUid());

		return $output;
	}

	/**
	 * Compute the value for the Content object according to a field name.
	 *
	 * @param \TYPO3\CMS\Vidi\Domain\Model\Content $object
	 * @param string $fieldNameAndPath
	 * @return string
	 */
	protected function resolveValue(Content $object, $fieldNameAndPath) {

		// Get the first part of the field name and
		$fieldName = $this->getFieldPathResolver()->stripFieldName($fieldNameAndPath);

		$value = $object[$fieldName];

		// Relation but contains no data.
		if (is_array($value) && empty($value)) {
			$value = '';
		} elseif ($value instanceof Content) {

			$fieldNameOfForeignTable = $this->getFieldPathResolver()->stripFieldPath($fieldNameAndPath);

			// TRUE means the field name does not contains a path. "title" vs "metadata.title"
			// Fetch the default label
			if ($fieldNameOfForeignTable === $fieldName) {
				$foreignTable = TcaService::table($object->getDataType())->field($fieldName)->getForeignTable();
				$fieldNameOfForeignTable = TcaService::table($foreignTable)->getLabelField();
			}

			$value = $object[$fieldName][$fieldNameOfForeignTable];
		}

		return $value;
	}

	/**
	 * Check whether a string contains HTML tags.
	 *
	 * @param string $string the content to be analyzed
	 * @return boolean
	 */
	protected function hasHtml($string) {
		$result = FALSE;

		// We compare the length of the string with html tags and without html tags.
		if (strlen($string) != strlen(strip_tags($string))) {
			$result = TRUE;
		}
		return $result;
	}

	/**
	 * Check whether a string contains potential XSS.
	 *
	 * @param string $string the content to be analyzed
	 * @return boolean
	 */
	protected function isClean($string) {

		// @todo implement me!
		$result = TRUE;
		return $result;
	}

	/**
	 * Process the value
	 *
	 * @todo implement me as a processor chain to be cleaner implementation wise. Look out at the performance however!
	 *       e.g DefaultValueGridProcessor, TextAreaGridProcessor, ...
	 *
	 * @param string $value
	 * @param \TYPO3\CMS\Vidi\Domain\Model\Content $object
	 * @param string $fieldNameAndPath
	 * @return string
	 */
	protected function processValue($value, Content $object, $fieldNameAndPath) {

		// Set default value if $field name correspond to the label of the table
		$fieldName = $this->getFieldPathResolver()->stripFieldPath($fieldNameAndPath);
		if (TcaService::table($object->getDataType())->getLabelField() === $fieldName && empty($value)) {
			$value = sprintf('[%s]', $this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:labels.no_title', 1));
		}

		// Resolve the identifier in case of "select" or "radio button".
		$fieldType = TcaService::table($object->getDataType())->field($fieldNameAndPath)->getType();
		if ($fieldType === TcaService::RADIO || $fieldType === TcaService::SELECT) {

			// Attempt to convert the value into a label for radio and select fields.
			$label = TcaService::table($object->getDataType())->field($fieldNameAndPath)->getLabelForItem($value);
			if ($label) {
				$value = $label;
			}
		} elseif ($fieldType !== TcaService::TEXTAREA) {
			$value = htmlspecialchars($value);
		} elseif ($fieldType === TcaService::TEXTAREA && !$this->isClean($value)) {
			$value = htmlspecialchars($value); // Avoid bad surprise, converts characters to HTML.
		} elseif ($fieldType === TcaService::TEXTAREA && !$this->hasHtml($value)) {
			$value = nl2br($value);
		}

		return $value;
	}


	/**
	 * Possible value formatting.
	 *
	 * @param string $value
	 * @param array $configuration
	 * @return string
	 */
	protected function formatValue($value, array $configuration) {
		if (empty($configuration['format'])) {
			return $value;
		}
		$className = $configuration['format'];

		// Support legacy formatter names which are not full qualified class names.
		if ($className === 'date' || $className === 'datetime') {
			$message = 'The Ext:vidi Grid configuration option "format" needs to be a full qualified class name since version 0.3.0.';
			$message .= 'Support for "date" and "datetime" will be removed two versions later.';
			GeneralUtility::deprecationLog($message);

			$className = 'TYPO3\\CMS\\Vidi\\Formatter\\' . ucfirst($className);
		}

		/** @var \TYPO3\CMS\Vidi\Formatter\FormatterInterface $formatter */
		$formatter = $this->objectManager->get($className);
		$value = $formatter->format($value);

		return $value;
	}

	/**
	 * Possible value wrapping.
	 *
	 * @param string $value
	 * @param array $configuration
	 * @return string
	 */
	protected function wrapValue($value, array $configuration) {
		if (!empty($configuration['wrap'])) {
			$parts = explode('|', $configuration['wrap']);
			$value = implode($value, $parts);
		}
		return $value;
	}

	/**
	 * Possible value wrapping.
	 *
	 * @param string $value
	 * @param \TYPO3\CMS\Vidi\Domain\Model\Content $object
	 * @param string $fieldNameAndPath
	 * @return string
	 */
	protected function prependSpriteIcon($value, Content $object, $fieldNameAndPath) {

		$fieldName = $this->getFieldPathResolver()->stripFieldPath($fieldNameAndPath);

		if (TcaService::table($object->getDataType())->getLabelField() === $fieldName) {
			$recordData = array();

			$enablesMethods = array('Hidden', 'Deleted', 'StartTime', 'EndTime');
			foreach ($enablesMethods as $enableMethod) {

				$methodName = 'get' . $enableMethod . 'Field';
				// Fetch possible hidden filed
				$enableField = TcaService::table($object)->$methodName();
				if ($enableField) {
					$recordData[$enableField] = $object[$enableField];
				}
			}

			// Get Enable Fields of the object to render the sprite with overlays.
			$spriteIcon = IconUtility::getSpriteIconForRecord($object->getDataType(), $recordData);
			$value = $spriteIcon . ' ' . $value;

		}
		return $value;
	}

	/**
	 * @return \TYPO3\CMS\Vidi\Resolver\FieldPathResolver
	 */
	protected function getFieldPathResolver () {
		return GeneralUtility::makeInstance('TYPO3\CMS\Vidi\Resolver\FieldPathResolver');
	}

	/**
	 * @return \TYPO3\CMS\Lang\LanguageService
	 */
	protected function getLanguageService() {
		return $GLOBALS['LANG'];
	}
}
