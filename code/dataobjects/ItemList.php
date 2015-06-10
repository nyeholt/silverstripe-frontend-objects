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
		'Sort'				=> 'Int',
	);
	
	private static $extensions = array(
		'Restrictable',
	);
	
	private static $default_sort = 'Sort ASC';
	
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
		
		$fields->replaceField('ItemType', DropdownField::create('ItemType', 'Items of type', $types));
		
		
		if ($this->ItemType) {
			$dummy = singleton($this->ItemType);
			
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
			
			$dbFields = array_merge($typeFields, $dbFields, $hasOnes, $additional);
			
			$fields->replaceField('Filter', KeyValueField::create('Filter', 'Filter by', $dbFields));
			$fields->replaceField('Include', KeyValueField::create('Include', 'Include where', $dbFields));
			
			$fields->replaceField('DataFields', KeyValueField::create('DataFields', 'Fields in table', $dbFields));
			
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
				'Company'	=> 'Companies',
				'Project'	=> 'Projects',
				'WorkItem'	=> 'Work items'
			);
		}
		$types = array_combine(array_values($types), array_values($types));
		return $types;
	}


	protected $items;
	
	public function getItems() {
		if ($this->items) {
			return $this->items;
		}
		$types = $this->allowedItems();
		if (isset($types[$this->ItemType])) {
			$items = DataList::create($this->ItemType);
			
			// add filter
			
			$filter = $this->Filter->getValues();
			$filterBy = array();
			if (count($filter)) {
				foreach ($filter as $field => $val) {
					$val = $this->resolveValue($val);
					$filterBy[$field] = $val;
				}
				$items = $items->filter($filterBy);
			}

			
			$sorts = $this->SortBy->getValues();
			if (count($sorts)) {
				$items = $items->sort($sorts);
			}
			
			$dbFields = $this->DataFields->getValues();
			if (!count($dbFields)) {
				$dbFields = array('ID' => 'ID', 'Title' => 'Title');
			}
			
			$representative = singleton($this->ItemType);
			if ($representative->hasMethod('updateItemListItems')) {
				$representative->updateItemListItems($items);
			}
			
			$formatting = array();
			if (method_exists($representative, 'getItemTableFormatting')) {
				$formatting = $representative->getItemTableFormatting();
			}
			
			// add filterAny
//			$items = $items->limit($this->getLimit());
			// grab the current request by any means possible
			$request = Controller::curr()->getRequest();
			$items = PaginatedList::create($items, $request);
			$items->setPageLength($this->getLimit());
			$items->setPaginationGetVar('list' . $this->ID);
			
			$remapped = ArrayList::create();
			foreach ($items as $item) {
				if (!$item->canView()) {
					continue;
				}
				$values = ArrayList::create();
				foreach ($dbFields as $field => $label) {
					if (isset($formatting[$field])) {
						$val = $this->formattingField($item, $formatting[$field]);
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

				$remapped->push(ArrayData::create(array('Item' => $item, 'ClassName' => $item->ClassName, 'ID' => $item->ID, 'Values' => $values)));
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
	
	protected function formattingField($item, $format) {
		$regex = '/\$Item\.([a-zA-Z0-9]+)/';
		
		$keywords = array();
		$replacements = array();
		if (preg_match_all($regex, $format, $matches)) {
			foreach ($matches[0] as $index => $keyword) {
				$field = $matches[1][$index];
				$replacement = '';
				if (method_exists($item, $field)) {
					$replacement = $item->$field();
				} else {
					$replacement = $item->$field;
				}
				$keywords[] = $keyword;
				$replacements[] = $replacement;
			}
		}
		$format = str_replace($keywords, $replacements, $format);
		return $format;
	}
	
	protected function getLimit() {
		if (!$this->Number) {
			$this->Number = 50;
		}
		return min(array($this->Number, 50));
	}
	
	public function forTemplate() {
		return $this->renderWith('ItemListView');
	}
}
