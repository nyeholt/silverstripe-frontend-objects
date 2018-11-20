<?php
namespace Symbiote\FrontendObjects\Page;

use PageController;

class ItemListPageController extends PageController
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
