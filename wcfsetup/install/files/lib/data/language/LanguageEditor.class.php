<?php
namespace wcf\data\language;
use wcf\data\language\category\LanguageCategory;
use wcf\data\language\category\LanguageCategoryEditor;
use wcf\data\language\item\LanguageItemEditor;
use wcf\data\language\item\LanguageItemList;
use wcf\data\DatabaseObjectEditor;
use wcf\data\IEditableCachedObject;
use wcf\system\cache\builder\LanguageCacheBuilder;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\exception\SystemException;
use wcf\system\io\File;
use wcf\system\language\LanguageFactory;
use wcf\system\Regex;
use wcf\system\WCF;
use wcf\util\DirectoryUtil;
use wcf\util\FileUtil;
use wcf\util\StringUtil;
use wcf\util\XML;

/**
 * Provides functions to edit languages.
 * 
 * @author	Alexander Ebert
 * @copyright	2001-2013 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf
 * @subpackage	data.language
 * @category	Community Framework
 */
class LanguageEditor extends DatabaseObjectEditor implements IEditableCachedObject {
	/**
	 * @see	\wcf\data\DatabaseObjectDecorator::$baseClass
	 */
	protected static $baseClass = 'wcf\data\language\Language';
	
	/**
	 * @see	\wcf\data\DatabaseObjectEditor::delete()
	 */
	public function delete() {
		parent::delete();
		
		self::deleteLanguageFiles($this->languageID);
	}
	
	/**
	 * Updates the language files for the given category.
	 * 
	 * @param	\wcf\data\language\category\LanguageCategory	$languageCategory
	 */
	public function updateCategory(LanguageCategory $languageCategory) {
		$this->writeLanguageFiles(array($languageCategory->languageCategoryID));
	}
	
	/**
	 * Write the languages files.
	 * 
	 * @param	array<integer>		$languageCategoryIDs
	 */
	protected function writeLanguageFiles(array $languageCategoryIDs) {
		$conditions = new PreparedStatementConditionBuilder();
		$conditions->add("languageID = ?", array($this->languageID));
		$conditions->add("languageCategoryID IN (?)", array($languageCategoryIDs));
		
		// get language items
		$sql = "SELECT	languageItem, languageItemValue, languageCustomItemValue,
				languageUseCustomValue, languageCategoryID
			FROM	wcf".WCF_N."_language_item
			".$conditions;
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute($conditions->getParameters());
		$items = array();
		while ($row = $statement->fetchArray()) {
			$languageCategoryID = $row['languageCategoryID'];
			if (!isset($items[$languageCategoryID])) {
				$items[$languageCategoryID] = array();
			}
			
			$items[$languageCategoryID][$row['languageItem']] = ($row['languageUseCustomValue']) ? $row['languageCustomItemValue'] : $row['languageItemValue'];
		}
		
		foreach ($items as $languageCategoryID => $languageItems) {
			$category = LanguageFactory::getInstance()->getCategoryByID($languageCategoryID);
			if ($category === null) {
				continue;
			}
			
			$content = "<?php\n/**\n* WoltLab Community Framework\n* language: ".$this->languageCode."\n* encoding: UTF-8\n* category: ".$category->languageCategory."\n* generated at ".gmdate("r")."\n* \n* DO NOT EDIT THIS FILE\n*/\n";
			foreach ($languageItems as $languageItem => $languageItemValue) {
				$content .= "\$this->items['".$languageItem."'] = '".str_replace("'", "\'", $languageItemValue)."';\n";
				
				// compile dynamic language variables
				if ($category->languageCategory != 'wcf.global' && strpos($languageItemValue, '{') !== false) {
					$output = LanguageFactory::getInstance()->getScriptingCompiler()->compileString($languageItem, $languageItemValue);
					$content .= "\$this->dynamicItems['".$languageItem."'] = '".str_replace("'", "\'", $output['template'])."';\n";
				}
			}
			
			$filename = WCF_DIR.'language/'.$this->languageID.'_'.$category->languageCategory.'.php';
			$file = new File($filename);
			FileUtil::makeWritable($filename);
			$file->write($content . '?>');
			$file->close();
		}
	}
	
	/**
	 * Exports this language.
	 */
	public function export($packageIDArray = array(), $exportCustomValues = false) {
		$conditions = new PreparedStatementConditionBuilder();
		$conditions->add("language_item.languageID = ?", array($this->languageID));
		
		// bom
		echo "\xEF\xBB\xBF";
		
		// header
		echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<language xmlns=\"http://www.woltlab.com\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xsi:schemaLocation=\"http://www.woltlab.com http://www.woltlab.com/XSD/maelstrom/language.xsd\" languagecode=\"".$this->languageCode."\" countrycode=\"".$this->countryCode."\">\n";
		
		// get items
		$items = array();
		if (!empty($packageIDArray)) {
			$conditions->add("language_item.packageID IN (?)", array($packageIDArray));
		}
		
		$sql = "SELECT		languageItem, " . ($exportCustomValues ? "CASE WHEN languageUseCustomValue > 0 THEN languageCustomItemValue ELSE languageItemValue END AS languageItemValue" : "languageItemValue") . ", languageCategory
			FROM		wcf".WCF_N."_language_item language_item
			LEFT JOIN	wcf".WCF_N."_language_category language_category
			ON		(language_category.languageCategoryID = language_item.languageCategoryID)
			".$conditions;
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute($conditions->getParameters());
		while ($row = $statement->fetchArray()) {
			$items[$row['languageCategory']][$row['languageItem']] = $row['languageItemValue'];
		}
		
		// sort categories
		ksort($items);
		
		foreach ($items as $category => $categoryItems) {
			// sort items
			ksort($categoryItems);
			
			// category header
			echo "\t<category name=\"".$category."\">\n";
			
			// items
			foreach ($categoryItems as $item => $value) {
				echo "\t\t<item name=\"".$item."\"><![CDATA[".StringUtil::escapeCDATA($value)."]]></item>\n";
			}
			
			// category footer
			echo "\t</category>\n";
		}
		
		// footer
		echo "</language>";
	}
	
	/**
	 * Imports language items from an XML file into this language.
	 * Updates the relevant language files automatically.
	 * 
	 * @param	\wcf\util\XML	$xml
	 * @param	integer		$packageID
	 * @param	boolean		$updateFiles
	 * @param	boolean		$updateExistingItems
	 */
	public function updateFromXML(XML $xml, $packageID, $updateFiles = true, $updateExistingItems = true) {
		$xpath = $xml->xpath();
		$usedCategories = array();
		
		// fetch categories
		$categories = $xpath->query('/ns:language/ns:category');
		foreach ($categories as $category) {
			$usedCategories[$category->getAttribute('name')] = 0;
		}
		
		if (empty($usedCategories)) return;
		
		// select existing categories
		$conditions = new PreparedStatementConditionBuilder();
		$conditions->add("languageCategory IN (?)", array(array_keys($usedCategories)));
		
		$sql = "SELECT	languageCategoryID, languageCategory
			FROM	wcf".WCF_N."_language_category
			".$conditions;
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute($conditions->getParameters());
		while ($row = $statement->fetchArray()) {
			$usedCategories[$row['languageCategory']] = $row['languageCategoryID'];
		}
		
		// create new categories
		foreach ($usedCategories as $categoryName => $categoryID) {
			if ($categoryID) continue;
			
			$category = LanguageCategoryEditor::create(array(
				'languageCategory' => $categoryName
			));
			$usedCategories[$categoryName] = $category->languageCategoryID;
		}
		
		// loop through categories to import items
		$itemData = array();
		foreach ($categories as $category) {
			$categoryName = $category->getAttribute('name');
			$categoryID = $usedCategories[$categoryName];
			
			// loop through items
			$elements = $xpath->query('child::*', $category);
			foreach ($elements as $element) {
				$itemName = $element->getAttribute('name');
				$itemValue = $element->nodeValue;
				
				$itemData[] = $this->languageID;
				$itemData[] = $itemName;
				$itemData[] = $itemValue;
				$itemData[] = $categoryID;
				if ($packageID) $itemData[] = $packageID;
			}
		}
		
		if (!empty($itemData)) {
			// insert/update a maximum of 50 items per run (prevents issues with max_allowed_packet)
			$step = ($packageID) ? 5 : 4;
			WCF::getDB()->beginTransaction();
			for ($i = 0, $length = count($itemData); $i < $length; $i += 50 * $step) {
				$parameters = array_slice($itemData, $i, 50 * $step);
				$repeat = count($parameters) / $step;
				
				$sql = "INSERT".(!$updateExistingItems ? " IGNORE" : "")." INTO		wcf".WCF_N."_language_item
								(languageID, languageItem, languageItemValue, languageCategoryID". ($packageID ? ", packageID" : "") . ")
					VALUES			".substr(str_repeat('(?, ?, ?, ?'. ($packageID ? ', ?' : '') .'), ', $repeat), 0, -2);
				
				if ($updateExistingItems) {
					$sql .= " ON DUPLICATE KEY
					UPDATE			languageItemValue = IF(languageItemOriginIsSystem = 0, languageItemValue, VALUES(languageItemValue)),
								languageCategoryID = VALUES(languageCategoryID),
								languageUseCustomValue = 0";
				}
				
				$statement = WCF::getDB()->prepareStatement($sql);
				$statement->execute($parameters);
			}
			WCF::getDB()->commitTransaction();
		}
		
		// update the relevant language files
		if ($updateFiles) {
			self::deleteLanguageFiles($this->languageID);
		}
		
		// delete relevant template compilations
		$this->deleteCompiledTemplates();
	}
	
	/**
	 * Deletes the language cache.
	 * 
	 * @param	string		$languageID
	 * @param	string		$category
	 */
	public static function deleteLanguageFiles($languageID = '.*', $category = '.*') {
		if ($category != '.*') $category = preg_quote($category, '~');
		if ($languageID != '.*') $languageID = intval($languageID);
		
		DirectoryUtil::getInstance(WCF_DIR.'language/')->removePattern(new Regex($languageID.'_'.$category.'\.php$'));
	}
	
	/**
	 * Deletes relevant template compilations.
	 */
	public function deleteCompiledTemplates() {
		// templates
		DirectoryUtil::getInstance(WCF_DIR.'templates/compiled/')->removePattern(new Regex('.*_'.$this->languageID.'_.*\.php$'));
		// acp templates
		DirectoryUtil::getInstance(WCF_DIR.'acp/templates/compiled/')->removePattern(new Regex('.*_'.$this->languageID.'_.*\.php$'));
	}
	
	/**
	 * Updates all language files of the given package id.
	 */
	public static function updateAll() {
		self::deleteLanguageFiles();
	}
	
	/**
	 * Takes an XML object and returns the specific language code.
	 * 
	 * @param	\wcf\util\XML	$xml
	 * @return	string
	 */
	public static function readLanguageCodeFromXML(XML $xml) {
		$rootNode = $xml->xpath()->query('/ns:language')->item(0);
		$attributes = $xml->xpath()->query('attribute::*', $rootNode);
		foreach ($attributes as $attribute) {
			if ($attribute->name == 'languagecode') {
				return $attribute->value;
			}
		}
		
		throw new SystemException("missing attribute 'languagecode' in language file");
	}
	
	/**
	 * Takes an XML object and returns the specific language name.
	 * 
	 * @param	\wcf\util\XML	$xml
	 * @return	string		language name
	 */
	public static function readLanguageNameFromXML(XML $xml) {
		$rootNode = $xml->xpath()->query('/ns:language')->item(0);
		$attributes = $xml->xpath()->query('attribute::*', $rootNode);
		foreach ($attributes as $attribute) {
			if ($attribute->name == 'languagename') {
				return $attribute->value;
			}
		}
		
		throw new SystemException("missing attribute 'languagename' in language file");
	}
	
	/**
	 * Takes an XML object and returns the specific country code.
	 * 
	 * @param	\wcf\util\XML	$xml
	 * @return	string		country code
	 */
	public static function readCountryCodeFromXML(XML $xml) {
		$rootNode = $xml->xpath()->query('/ns:language')->item(0);
		$attributes = $xml->xpath()->query('attribute::*', $rootNode);
		foreach ($attributes as $attribute) {
			if ($attribute->name == 'countrycode') {
				return $attribute->value;
			}
		}
		
		throw new SystemException("missing attribute 'countrycode' in language file");
	}
	
	/**
	 * Imports language items from an XML file into a new or a current language.
	 * Updates the relevant language files automatically.
	 * 
	 * @param	\wcf\util\XML	$xml
	 * @param	integer		$packageID
	 * @return	\wcf\data\language\LanguageEditor
	 */
	public static function importFromXML(XML $xml, $packageID) {
		$languageCode = self::readLanguageCodeFromXML($xml);
		
		// try to find an existing language with the given language code
		$language = LanguageFactory::getInstance()->getLanguageByCode($languageCode);
		
		// create new language
		if ($language === null) {
			$countryCode = self::readCountryCodeFromXML($xml);
			$languageName = self::readLanguageNameFromXML($xml);
			$language = self::create(array(
				'countryCode' => $countryCode,
				'languageCode' => $languageCode,
				'languageName' => $languageName
			));
		}
		
		// import xml
		$languageEditor = new LanguageEditor($language);
		$languageEditor->updateFromXML($xml, $packageID);
		
		// return language object
		return $languageEditor;
	}
	
	/**
	 * Copies all language variables from current language to language specified as $destination.
	 * Caution: This method expects that target language does not have any items!
	 * 
	 * @param	Language	$destination
	 */
	public function copy(Language $destination) {
		$sql = "INSERT INTO	wcf".WCF_N."_language_item
					(languageID, languageItem, languageItemValue, languageItemOriginIsSystem, languageCategoryID, packageID)
			SELECT		?, languageItem, languageItemValue, languageItemOriginIsSystem, languageCategoryID, packageID
			FROM		wcf".WCF_N."_language_item
			WHERE		languageID = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array(
			$destination->languageID,
			$this->languageID
		));
	}
	
	/**
	 * Updates the language items of a language category.
	 * 
	 * @param	array						$items
	 * @param	\wcf\data\language\category\LanguageCategory	$category
	 * @param	integer						$packageID
	 * @param	array						$useCustom
	 */
	public function updateItems(array $items, LanguageCategory $category, $packageID = PACKAGE_ID, array $useCustom = array()) {
		if (empty($items)) return;
		
		// find existing language items
		$languageItemList = new LanguageItemList();
		$languageItemList->getConditionBuilder()->add("language_item.languageItem IN (?)", array(array_keys($items)));
		$languageItemList->getConditionBuilder()->add("languageID = ?", array($this->languageID));
		$languageItemList->readObjects();
		
		foreach ($languageItemList->getObjects() as $languageItem) {
			$languageItemEditor = new LanguageItemEditor($languageItem);
			$languageItemEditor->update(array(
				'languageCustomItemValue' => $items[$languageItem->languageItem],
				'languageUseCustomValue' => (isset($useCustom[$languageItem->languageItem])) ? 1 : 0
			));
			
			// remove updated items, leaving items to be created within
			unset($items[$languageItem->languageItem]);
		}
		
		// create remaining items
		if (!empty($items)) {
			// bypass LanguageItemEditor::create() for performance reasons
			$sql = "INSERT INTO	wcf".WCF_N."_language_item
				(languageID, languageItem, languageItemValue, languageItemOriginIsSystem, languageCategoryID, packageID)
				VALUES		(?, ?, ?, ?, ?, ?)";
			$statement = WCF::getDB()->prepareStatement($sql);
			
			foreach ($items as $itemName => $itemValue) {
				$statement->execute(array(
					$this->languageID,
					$itemName,
					$itemValue,
					0,
					$category->languageCategoryID,
					$packageID
				));
			}
		}
		
		// update the relevant language files
		self::deleteLanguageFiles($this->languageID, $category->languageCategory, $packageID);
		
		// delete relevant template compilations
		$this->deleteCompiledTemplates();
	}
	
	/**
	 * Sets current language as default language.
	 */
	public function setAsDefault() {
		// remove default flag from all languages
		$sql = "UPDATE	wcf".WCF_N."_language
			SET	isDefault = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array(
			0
		));
		
		// set current language as default language
		$this->update(array(
			'isDefault' => 1
		));
		
		$this->clearCache();
	}
	
	/**
	 * Clears language cache.
	 */
	public function clearCache() {
		LanguageCacheBuilder::getInstance()->reset();
	}
	
	/**
	 * Searches in language items.
	 * 
	 * @param	string		$search		search query
	 * @param	string		$replace
	 * @param	integer		$languageID
	 * @param	boolean		$useRegex
	 * @param	boolean		$caseSensitive
	 * @param	boolean		$searchVariableName
	 * @return	array
	 */
	public static function search($search, $replace = null, $languageID = null, $useRegex = 0, $searchVariableName = 0) {
		$results = array();
		
		// build condition
		$conditionBuilder = new PreparedStatementConditionBuilder();
		
		// search field
		$statementParameters = array();
		if ($searchVariableName) $searchCondition = 'languageItem ';
		else $searchCondition = 'languageItemValue ';
		
		// regex
		if ($useRegex) {
			$searchCondition .= "REGEXP ?";
			$statementParameters[] = $search;
		}
		else {
			$searchCondition .= "LIKE ?";
			$statementParameters[] = '%'.$search.'%';
		}
		
		if (!$searchVariableName) {
			$searchCondition .= ' OR languageCustomItemValue ';
			// regex
			if ($useRegex) {
				$searchCondition .= "REGEXP ?";
				$statementParameters[] = $search;
			}
			else {
				$searchCondition .= "LIKE ?";
				$statementParameters[] = '%'.$search.'%';
			}
		}
		
		$conditionBuilder->add($searchCondition, $statementParameters);
		if ($languageID !== null) $conditionBuilder->add("languageID = ?", array($languageID));
		
		// search
		$updatedItems = array();
		$sql = "SELECT		*
			FROM		wcf".WCF_N."_language_item
			".$conditionBuilder;
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		
		while ($row = $statement->fetchArray()) {
			if ($replace !== null) {
				// search and replace
				$matches = 0;
				if ($useRegex) {
					$newValue = preg_replace('~'.$search.'~s', $replace, ($row['languageCustomItemValue'] ?: $row['languageItemValue']), -1, $matches);
				}
				else {
					$newValue = StringUtil::replaceIgnoreCase($search, $replace, ($row['languageCustomItemValue'] ?: $row['languageItemValue']), $matches);
				}
				
				if ($matches > 0) {
					// update value
					if (!isset($updatedItems[$row['languageID']])) $updatedItems[$row['languageID']] = array();
					if (!isset($updatedItems[$row['languageID']][$row['languageCategoryID']])) $updatedItems[$row['languageID']][$row['languageCategoryID']] = array();
					$updatedItems[$row['languageID']][$row['languageCategoryID']][$row['languageItem']] = $newValue;
					
					// save matches
					$row['matches'] = $matches;
				}
			}
			
			$results[] = $row;
		}
		
		// save updates
		if (!empty($updatedItems)) {
			foreach ($updatedItems as $languageID => $categories) {
				$language = new LanguageEditor($languageID);
				
				foreach ($categories as $categoryID => $items) {
					$useCustom = array();
					foreach (array_keys($items) as $item) {
						$useCustom[$item] = 1;
					}
					
					$category = new LanguageCategory($categoryID);
					$language->updateItems($items, $category, PACKAGE_ID, $useCustom);
				}
			}
		}
		
		return $results;
	}
	
	/**
	 * Enables the multilingualism feature for given languages.
	 * 
	 * @param	array		$languageIDs
	 */
	public static function enableMultilingualism(array $languageIDs = array()) {
		$sql = "UPDATE	wcf".WCF_N."_language
			SET	hasContent = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array(0));
		
		if (!empty($languageIDs)) {
			$sql = '';
			$statementParameters = array();
			foreach ($languageIDs as $languageID) {
				if (!empty($sql)) $sql .= ',';
				$sql .= '?';
				$statementParameters[] = $languageID;
			}
			
			$sql = "UPDATE	wcf".WCF_N."_language
				SET	hasContent = ?
				WHERE	languageID IN (".$sql.")";
			$statement = WCF::getDB()->prepareStatement($sql);
			array_unshift($statementParameters, 1);
			$statement->execute($statementParameters);
		}
	}
	
	/**
	 * @see	\wcf\data\IEditableCachedObject::resetCache()
	 */
	public static function resetCache() {
		LanguageFactory::getInstance()->clearCache();
	}
}
