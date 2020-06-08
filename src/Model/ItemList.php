<?php

namespace Symbiote\FrontendObjects\Model;


use ReflectionObject;
use ReflectionMethod;

use SilverStripe\Forms\DropdownField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;
use SilverStripe\ORM\PaginatedList;
use SilverStripe\ORM\DataList;
use SilverStripe\Core\Convert;
use SilverStripe\Control\Controller;
use SilverStripe\Security\Member;
use SilverStripe\Core\Config\Config;
use SilverStripe\View\Parsers\URLSegmentFilter;
use SilverStripe\View\Requirements;
use SilverStripe\Security\Permission;
use SilverStripe\ORM\DataObject;
use Symbiote\MultiValueField\Fields\KeyValueField;
use SilverStripe\ORM\FieldType\DBText;



/**
 * @author marcus
 */
class ItemList extends DataObject
{

    private static $table_name = 'ItemList';

    private static $allowed_funcs = array(
        'strtotime' => true,
        'date' => true,
    );

    private static $db = array(
        'Title'                => 'Varchar',
        'Global'            => 'Boolean',
        'ItemType'            => 'Varchar',
        'Filter'            => 'MultiValueField',
        'Include'            => 'MultiValueField',
        'SortBy'            => 'MultiValueField',
        'Number'            => 'Int',
        'DataFields'        => 'MultiValueField',
        'FieldFormatting'    => 'MultiValueField',
        'ShowTitle'            => 'Boolean',
        'ShowCreate'        => 'Boolean',
        'Exportable'        => 'Boolean',
        'Sort'                => 'Int',
    );

    private static $defaults = array(
        'ShowTitle'        => 1,
        'ShowCreate'    => 0,
    );

    private static $default_sort = 'Sort ASC';

    private static $list_types = array('Page' => 'Pages');

    /**
	 * The context that this list is displayed in, used for link building
	 * @var Controller
	 */
    protected $contextLink = null;

    protected $createLink = null;

    /**
	 * A list of modifiers that can be bound to the item list to change the filtered
	 * items list. Mostly useful for code that's creating an item list directly.
	 *
	 * @var array
	 */
    protected $listModifiers = array();

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        if (!$this->Number) {
            $this->Number = 50;
        }
        $types = $this->allowedItems();
        if (!isset($types[$this->ItemType])) {
            $this->ItemType = '';
        }
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $this->updateFields($fields);

        return $fields;
    }

    public function updateFields($fields)
    {
        $types = $this->allowedItems();

        $fields->replaceField('ItemType', DropdownField::create('ItemType', 'Items of type', $types)->setEmptyString('--select type--'));

        if ($this->ItemType) {
            $list = $this->getFilteredItemList(false);
            $dummy = $list->first();
            if (!$dummy) {
                $dummy = singleton($this->ItemType);
            }
            $dbFields = $dummy->stat('db');
            $dbFields = array_combine(array_keys($dbFields), array_keys($dbFields));
            $typeFields = array(
                'ID' => 'ID',
                'LastEdited' => 'LastEdited',
                'Created' => 'Created',
            );

            $additional = $dummy->summaryFields();

            $hasOnes = $dummy->stat('has_one');

            foreach ($hasOnes as $relName => $relType) {
                $dbFields[$relName . 'ID'] = $relName . 'ID';
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

    public function getFrontEndFields($params = null)
    {
        $fields = parent::getFrontEndFields($params);
        $this->updateFields($fields);
        return $fields;
    }

    protected function allowedItems()
    {
        $types = self::config()->list_types;
        if (!count($types)) {
            $types = array(
                'Page'    => 'Pages',
            );
        }

        return $types;
    }


    protected $items;

    public function getItems()
    {
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
                // copy of the array
                $itemFormatting = $formatting;
                // called here to allow extensions to update the data that the formatting logic can use
                $item->extend('updateItemTableFormatting', $itemFormatting);
                $values = ArrayList::create();
                foreach ($dbFields as $field => $label) {
                    if (isset($itemFormatting[$field])) {
                        $val = $this->formatField($item, $itemFormatting[$field]);
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
                        'ActionsField'    => true
                    )));
                }

                $displayItem = ArrayData::create(array('Item' => $item, 'ClassName' => $item->ClassName, 'ID' => $item->ID, 'Values' => $values));
                $remapped->push($displayItem);
            }

            $remapped = PaginatedList::create($remapped);

            $remapped->setPaginationGetVar($this->paginationName());
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

    public function getFilteredItemList($paginated = true)
    {
        $items = DataList::create($this->ItemType);

        // add filter
        $filter = $this->Filter->getValues();
        $filterBy = array();
        if (count($filter)) {
            foreach ($filter as $field => $val) {
                $field = Convert::raw2sql($field);
                $val = $this->resolveValue($val);
                if (is_null($val)) {
                    $items = $items->where('"' . $field . '" IS NULL');
                } else {
                    $filterBy[$field] = $val;
                }
            }
            $items = $items->filter($filterBy);
        }

        $or = $this->Include->getValues();
        if (count($or)) {
            $orBy = array();
            foreach ($or as $field => $val) {
                $field = Convert::raw2sql($field);
                $val = $this->resolveValue($val, $optFilter);
                $fieldIndex = $optFilter ? $field . ':' . $optFilter : $field;
                $orBy[$fieldIndex] = $val;
            }
            $items = $items->filterAny($orBy);
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
            $items->setPaginationGetVar($this->paginationName());
        }

        return $items;
    }

    protected function resolveValue($val, &$filter = null)
    {
        if (!$val) {
            return $val;
        }
        // check for a filter
        if (preg_match('{^([a-z]+):(.*)}i', $val, $matches)) {
            if ($matches[1] == 'IN') {
                $val = explode(',', $matches[2]);
            } else {
                $val = $matches[2];
                $filter = $matches[1];
            }
        }

        // see if we're a function call
        if (is_scalar($val) && preg_match('/([a-z0-9_]+)\((.*)\)/i', $val, $matches)) {
            $func = $matches[1];
            $allowed = $this->config()->allowed_funcs;
            if (isset($allowed[$func]) && $allowed[$func]) {
                $args = explode(',', $matches[2]);
                $args = array_map(array($this, 'resolveValue'), $args);
                $res = call_user_func_array($func, $args);
                return $res;
            }
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
    public function addFilterModifier($modifier)
    {
        if (is_callable($modifier)) {
            $this->listModifiers[] = $modifier;
        }
    }

    public function tableHeaders()
    {
        $dbFields = $this->DataFields->getValues();
        if (count($dbFields)) {
            $list = ArrayList::create();
            foreach ($dbFields as $field => $label) {
                $list->push(ArrayData::create(array(
                    'FieldName' => $field,
                    'Label' => $label
                )));
            }
            if ($actions = $this->actionsForType($this->ItemType)) {
                $list->push(ArrayData::create(array('Label' => 'Actions')));
            }
            return $list;
        }
    }

    protected function actionsForType($type)
    {
        $representative = singleton($type);
        if (method_exists($representative, 'ItemTableActions')) {
            return $representative->ItemTableActions();
        }
    }

    protected function formatField($item, $format)
    {
        $regex = '/\$Item\.([a-zA-Z0-9.]+)/';

        $keywords = array();
        $replacements = array();

        if (preg_match_all($regex, $format, $matches)) {
            $allowed = $this->getAllowedMethods($item);
            foreach ($matches[0] as $index => $keyword) {
                $fieldModifier = explode('.', $matches[1][$index]);
                $field = $fieldModifier[0];
                $modifier = null;
                if (isset($fieldModifier[1])) {
                    $modifier = $fieldModifier[1];
                }
                $replacement = '';
                if ($item->hasMethod($field) && isset($allowed[$field])) {
                    $replacement = $item->$field();
                } else {
                    $replacement = $item->$field;
                    // captures array / closure, but skips plain global functions
                    if (!is_string($replacement) && is_callable($replacement)) {
                        $replacement = $replacement();
                    }
                }

                $keywords[] = $keyword;
                $output = DBText::create_field('DBText', $replacement);
                if ($modifier) {
                    $output = $output->$modifier();
                }
                $replacements[] = $output;
            }
        }
        $format = str_replace($keywords, $replacements, $format);
        return $format;
    }

    protected $allowedMethods = array();
    /**
	 * Gets the list of methods that can be called from a template.
	 *
	 * Checks if there's any explicitly defined methods, if not makes available
	 * any publicly defined methods on the object directly.
	 *
	 * @param DataObject $item
	 * @return array
	 */
    protected function getAllowedMethods($item)
    {
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

    protected function paginationName()
    {
        $name = $this->ID;
        if (!$name) {
            $name = URLSegmentFilter::create()->filter($this->Title);
        }
        return 'list' . $name;
    }

    protected function getLimit()
    {
        if (!$this->Number) {
            $this->Number = 50;
        }
        return min(array($this->Number, 50));
    }

    public function setContextLink($link)
    {
        $this->contextLink = $link;
    }

    public function CreateLink()
    {
        if ($this->createLink) {
            return $this->createLink;
        }
        if ($this->ShowCreate) {
            $this->createLink = 'frontend-admin/model/' . strtolower($this->ItemType) . '/edit';
        }
        return $this->createLink;
    }

    public function setCreateLink($link)
    {
        $this->createLink = $link;
    }

    public function Link()
    {
        if ($this->contextLink) {
            return $this->contextLink;
        }
        return 'frontend-admin/model/itemlist/showlist/' . $this->ID;
    }

    public function ExportLink()
    {
        if ($this->exportLink) {
            return $this->exportLink;
        }
        return 'frontend-admin/model/itemlist/showlist/' . $this->ID . '.csv';
    }

    public function forTemplate($template = null)
    {
        Requirements::javascript('frontend-objects/javascript/frontend-sidebar.js');
        Requirements::javascript('frontend-objects/javascript/frontend-itemtable.js');

        Requirements::css('frontend-objects/css/frontend-objects.css');

        $templates = array();
        if ($this->ItemType) {
            $templates[] = 'ItemListView_' . $this->ItemType;
        }

        if ($this->ShowCreate && !$this->createLink) {
            $this->createLink = 'frontend-admin/model/' . strtolower($this->ItemType) . '/edit';
        }

        $templates[] = 'ItemListView';
        return $this->renderWith($template ? $template : $templates);
    }

    public function toCSV()
    {
        $content = $this->forTemplate('ItemListCsv');
        return $content;
    }

    public function canView($member = null)
    {
        $can = parent::canView($member);
        return $can ? $can : Permission::check('CMS_ACCESS_FrontendAdmin');
    }

    public function canEdit($member = null)
    {
        $can = parent::canEdit($member);
        return $can ? $can : Permission::check('CMS_ACCESS_FrontendAdmin');
    }

    public function canDelete($member = null)
    {
        $can = parent::canDelete($member);
        return $can ? $can : Permission::check('CMS_ACCESS_FrontendAdmin');
    }

    public static function live_editable_field($field, $type, $editor, $attrs = array())
    {
        if (is_array($editor)) {
            $attrs = $editor;
            $editor = $editor['data-editortype'];
        } else {
            $attrs['data-editortype'] = $editor;
        }
        $attrs['data-property'] = $field;
        $attrs['class'] = 'live-editable';

        $attrs['data-object'] = array('Type' => $type, 'ID' => '$Item.ID');
        $attrstr = '';
        foreach ($attrs as $key => $val) {

            if ($key == 'data-items' && $editor == 'dropdown') {
                $newVal = array();
                // remap to k, v object to ensure sorted array order
                foreach ($val as $k => $v) {
                    $newVal[] = array('k' => $k, 'v' => $v);
                }
                $val = $newVal;
            }

            if (is_array($val)) {
                $val = json_encode($val);
            }
            $val = Convert::raw2att($val);
            $attrstr .= "$key='$val' ";
        }
        $field = "<span $attrstr>\$Item.$field</span>";
        return $field;
    }
}
