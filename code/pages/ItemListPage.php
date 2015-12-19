<?php

/**
 * Page type for embedding itemlist(s)
 * 
 * @author marcus
 */
class ItemListPage extends Page
{
    private static $db = array(
        'ShowAddButton'        => 'Boolean',
    );

    private static $has_one = array(
        'ItemList'                => 'ItemList',
    );
    
    private static $many_many = array(
        'EditingForm'            => 'Page',
    );
    
    
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        
        $field = DropdownField::create('ItemListID', 'Item list to display', ItemList::get()->map()->toArray())->setEmptyString('--choose list--');
        $fields->addFieldToTab('Root.Main', $field, 'Content');
        
        if ($this->hasField('TemplateID')) {
            $fields->addFieldToTab('Root.Main', $df = DropdownField::create('TemplateID', 'Template for rendering items', UserTemplate::get()->map()->toArray()), 'Content');
            $df->setEmptyString('--template--');
        }
        
        return $fields;
    }
    
    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        
        if (!$this->Content) {
            $this->Content = '<p>$List</p>';
        }
    }
    
    public function Content()
    {
        $listContent = '';
        if ($this->ItemListID) {
            $list = $this->ItemList();
            $template = null;
            if ($this->TemplateID) {
                $template = $this->Template()->getTemplateFile();
            }
            $list->setContextLink($this->Link('showlist'));
            $listContent = $list->forTemplate($template);
        }
        $obj = $this->dbObject('Content');
        $raw = $obj->raw();
        $raw = str_replace('$List', $listContent, $raw);
        $obj->setValue($raw);
        return $obj;
    }
}

class ItemListPage_Controller extends Page_Controller
{
    
    private static $allowed_actions = array('showlist');
    
    public function showlist()
    {
        $list = $this->data()->ItemList();
        if ($list->ID) {
            $template = null;
            if ($this->data()->TemplateID) {
                $template = $this->data()->Template()->getTemplateFile();
            }
            $list->setContextLink($this->Link('showlist'));
            $listContent = $list->forTemplate($template);
            return $listContent;
        }
    }
}
