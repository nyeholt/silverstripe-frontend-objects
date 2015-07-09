<?php

/**
 * @author marcus
 */
class ItemList extends DataObject {
	private static $db = array(
		'Title'				=> 'Varchar',
		'Global'			=> 'Boolean',
		'ItemType'			=> 'Varchar',
		'Filter'			=> 'MultiValueField',
		'Include'			=> 'MultiValueField',
		'SortBy'			=> 'MultiValueField',
		'Number'			=> 'Int',
		'DataFields'		=> 'MultiValueField',
		'FieldFormatting'	=> 'MultiValueField',
		'Sort'				=> 'Int',
	);
	
	private static $extensions = array(
		'Restrictable',
	);
	
	private static $default_sort = 'Sort ASC';
	
	private static $list_types = array('Page' => 'Pages');
	
	/** 
	 * The context that this list is displayed in, used for link building
	 * @var Controller 
	 */
	protected $contextLink = null;
	
	/**
	 * A list of modifiers that can be bound to the item list to change the filtered 
	 * items list. Mostly useful for code that's creating an item list directly. 
	 *
	 * @var array
	 */
	protected $listModifiers = array();
	
	public function onBeforeWrite() {
		parent::onBeforeWrite();
		if (!$this->Number) {
			$this->Number = 50;
		}
		$types = $this->allowedItems();
		if (!isset($types[$this->ItemType])) {
			$this->ItemType = '';
		}
	}
	
	public function getCMSFields() {
		$fields = parent::getCMSFields();
		
		$this->updateFields($fields);
		
		return $fields;
	}
	
	public function updateFields($fields) {
		$types = $this->allowedItems();
		
		$fields->replaceField('ItemType', DropdownField::create('ItemType', 'Items of type', $types)->setEmptyString('--select type--'));
		
		
		if ($this->ItemType) {
			$list = $this->getFilteredItemList(false);
			$dummy = $list->first();
			if (!$dummy) {
				$dummy = singleton($this->ItemType);
			}
			$dbFields = $dummy->db();
			$dbFields = array_combine(array_keys($dbFields), array_keys($dbFields));
			$typeFields = array(
				'ID' => 'ID',
				'LastEdited' => 'LastEdited',
				'Created' => 'Created',
			);
			
			$additional = $dummy->summaryFields();
			
			$hasOnes = $dummy->has_one();
			
			foreach ($hasOnes as $relName => $relType) {
				$dbFields[$relName.'ID'] = $relName .'ID';
			}

			// $hasOnes, 
			$dbFields = array_merge($typeFields, $dbFields); //, $additional);
			
			$fields->replaceField('Filter', KeyValueField::create('Filter', 'Filter by', $dbFields));
			$fields->replaceField('Include', KeyValueField::create('Include', 'Include where', $dbFields));
			
			$displayAble = array_merge($dbFields, $additional);
			$fields->replaceField('DataFields', KeyValueField::create('DataFields', 'Fields in table', $displayAble));

			$fields->replaceField('FieldFormatting', KeyValueField::create('FieldFormatting', 'Formatting for fields', $displayAble));
			
			$fields->replaceField('SortBy', KeyValueField::create('SortBy', 'Sorting', $dbFields, array('ASC' => 'ASC', 'DESC' => 'DESC')));
		}
	}
	
	public function getFrontEndFields($params = null) {
		$fields = parent::getFrontEndFields($params);
		$this->updateFields($fields);
		return $fields;
	}
	
	protected function allowedItems() {
		$types = self::config()->list_types;
		if (!count($types)) {
			$types = array(
				'Page'	=> 'Pages',
			);
		}

		return $types;
	}


	protected $items;
	
	public function getItems() {
		if ($this->items) {
			return $this->items;
		}
		$types = $this->allowedItems();
		if (isset($types[$this->ItemType])) {
			$items = $this->getFilteredItemList();

			$dbFields = $this->DataFields->getValues();
			if (!count($dbFields)) {
				$dbFields = array('ID' => 'ID', 'Title' => 'Title');
			}
			
			$representative = singleton($this->ItemType);
			$representative->extend('updateItemListItems', $items);
			
			$formatting = array();
			if (method_exists($representative, 'getItemTableFormatting')) {
				$formatting = $representative->getItemTableFormatting();
			} 
			
			$userDefined = $this->FieldFormatting->getValues();
			if (count($userDefined)) {
				$formatting = array_merge($formatting, $userDefined);
			}

			$remapped = ArrayList::create();
			foreach ($items as $item) {
				if (!$item->canView()) {
					continue;
				}
				// called here to allow extensions to update the data that the formatting logic can use
				$item->extend('updateItemTableFormatting', $formatting);
				$values = ArrayList::create();
				foreach ($dbFields as $field => $label) {
					if (isset($formatting[$field])) {
						$val = $this->formatField($item, $formatting[$field]);
					} else {
						$val = $item->$field;
					}

					$values->push(ArrayData::create(array(
						'Label' => $field, 
						'Value' => $val
					)));
				}
				if ($actions = $this->actionsForType($this->ItemType)) {
					$values->push(ArrayData::create(array(
						'Label' => 'Actions', 
						'Value' => $actions,
						'ActionsField'	=> true
					)));
				}

				$displayItem = ArrayData::create(array('Item' => $item, 'ClassName' => $item->ClassName, 'ID' => $item->ID, 'Values' => $values));
				$remapped->push($displayItem);
			}

			$remapped = PaginatedList::create($remapped);
			
			$remapped->setPaginationGetVar('list' . $this->ID);
			$remapped->setPageLength($items->getPageLength());
			$remapped->setPageStart($items->getPageStart());
			$remapped->setTotalItems($items->getTotalItems());
			$remapped->setCurrentPage($items->CurrentPage());
			$remapped->setLimitItems(false);

			$this->items = $remapped;
//			$remapped->setTotalItems($unlimited);
//			$remapped->setLimitItems($limit)
			return $remapped;
		}
	}
	
	public function getFilteredItemList($paginated = true) {
		$items = DataList::create($this->ItemType);

		// add filter
		$filter = $this->Filter->getValues();
		$filterBy = array();
		if (count($filter)) {
			foreach ($filter as $field => $val) {
				$val = $this->resolveValue($val);
				if ($val) {
					$filterBy[$field] = $val;
				}
			}
			$items = $items->filter($filterBy);
		}

		$sorts = $this->SortBy->getValues();
		if (count($sorts)) {
			$items = $items->sort($sorts);
		}
		
		foreach ($this->listModifiers as $modifier) {
			$items = $modifier($items);
		}
		
		if ($paginated && $this->getLimit()) {
			$request = Controller::curr()->getRequest();
			$items = PaginatedList::create($items, $request);
			$items->setPageLength($this->getLimit());
			$items->setPaginationGetVar('list' . $this->ID);
		}
		
		return $items;
	}
	
	protected function resolveValue($val) {
		if (strpos($val, 'IN:') === 0) {
			$val = explode(',', substr($val, 3));
		}
		if (is_array($val)) {
			$self = $this;
			array_walk($val, function (&$arrayValue) use ($self) {
				$arrayValue = $self->resolveValue($arrayValue);
			});
		} else {
			if (preg_match('/\$Member\.([a-z0-9]+)/i', $val, $matches)) {
				$key = $matches[1];
				$val = Member::currentUser()->$key;
			}
		}
		
		return $val;
	}
	
	/**
	 * Add a closure to be called against the item list prior to returning it
	 * 
	 * @param closure $modifier
	 */
	public function addFilterModifier($modifier) {
		if (is_callable($modifier)) {
			$this->listModifiers[] = $modifier;
		}
	}
	
	public function tableHeaders() {
		$dbFields = $this->DataFields->getValues();
		if (count($dbFields)) {
			$list = ArrayList::create();
			foreach ($dbFields as $field => $label) {
				$list->push(ArrayData::create(array('Label' => $label)));
			}
			if ($actions = $this->actionsForType($this->ItemType)) {
				$list->push(ArrayData::create(array('Label' => 'Actions')));
			}
			return $list;
		}
	}
	
	protected function actionsForType($type) {
		$representative = singleton($type);
		if (method_exists($representative, 'ItemTableActions')) {
			return $representative->ItemTableActions();
		}
	}
	
	protected function formatField($item, $format) {
		$regex = '/\$Item\.([a-zA-Z0-9]+)/';
		
		$keywords = array();
		$replacements = array();
		if (preg_match_all($regex, $format, $matches)) {
			$allowed = $this->getAllowedMethods($item);
			foreach ($matches[0] as $index => $keyword) {
				$field = $matches[1][$index];
				$replacement = '';
				if ($item->hasMethod($field) && isset($allowed[$field])) {
					$replacement = $item->$field();
				} else {
					$replacement = $item->$field;
					if (is_callable($replacement)) {
						$replacement = $replacement();
					}
				}
				$keywords[] = $keyword;
				$replacements[] = $replacement;
			}
		}
		$format = str_replace($keywords, $replacements, $format);
		return $format;
	}
	
	protected $allowedMethods = array();
	protected function getAllowedMethods($item) {
		$cls = get_class($item);
		$allowed = isset($this->allowedMethods[$cls]) ? $this->allowedMethods[$cls] : null;
		if (!$allowed) {
			$conf = Config::inst()->get(get_class($item), 'allowed_template_methods');
			if ($conf) {
				$allowed = $conf;
			} else {
				$methodsFromObject = function ($obj) {
					$cls = get_class($obj);
					$clazz = new ReflectionObject($obj);
					$declaredMethods = $clazz->getMethods(ReflectionMethod::IS_PUBLIC);
					$methods = array();
					foreach ($declaredMethods as $method) {
						/* @var $method  ReflectionMethod  */
						if ($method->class == $cls) {
							$methods[$method->getName()] = $method->getName();
						}
					}
					return $methods;
				};

				$allowed = $methodsFromObject($item);

				$extensions = $item->getExtensionInstances();
				foreach ($extensions as $ext) {
					$add = $methodsFromObject($ext);
					$allowed = array_merge($allowed, $add);
				}
			}
			$this->allowedMethods[$cls] = $allowed;
		}
		return $allowed;
	}
	
	protected function getLimit() {
		if (!$this->Number) {
			$this->Number = 50;
		}
		return min(array($this->Number, 50));
	}
	
	public function setContextLink($link) {
		$this->contextLink = $link;
	}
	
	public function Link() {
		if ($this->contextLink) {
			return $this->contextLink;
		}
		return 'frontend-admin/model/itemlist/showlist/' . $this->ID;
	}
	
	public function forTemplate($template = null) {
		Requirements::javascript('frontend-objects/javascript/frontend-sidebar.js');
		Requirements::javascript('frontend-objects/javascript/frontend-itemtable.js');

		Requirements::css('frontend-objects/css/frontend-objects.css');
		
		$templates = array();
		if ($this->ItemType) {
			$templates[] = 'ItemListView_' . $this->ItemType;
		}
		$templates[] = 'ItemListView';
		return $this->renderWith($template ? $template : $templates);
	}
	
	public function canView($member = null) {
		return true;
	}
	
	public function canEdit($member = null) {
		return Permission::check('CMS_ACCESS_FrontendAdmin');
	}

	public function canDelete($member = null) {
		return Permission::check('CMS_ACCESS_FrontendAdmin');
	}
	
	public static function live_editable_field($field, $type, $attrs) {
		$attrs['data-property'] = $field;
		$attrs['class'] = 'live-editable';
		if (!isset($attrs['style'])) {
			$attrs['style'] = 'min-width: 5px; display: inline-block;';
		}
		$attrs['data-object'] = array('Type' => $type, 'ID' => '$Item.ID');
		$attrstr = '';
		foreach ($attrs as $key => $val) {
			if (is_array($val)) {
				$val = json_encode($val);
			}
			$attrstr .= "$key='$val' ";
		}
		$field = "<span $attrstr>\$Item.$field</span>";
		return $field;
	}
}
