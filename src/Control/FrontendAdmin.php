<?php

namespace Symbiote\FrontendObjects\Control;

use Symbiote\FrontendObjects\Model\ItemList;
use SilverStripe\Admin\ModelAdmin;

/**
 *
 * @author marcus
 */
class FrontendAdmin extends ModelAdmin {
	private static $menu_title = 'Frontend Model Settings';
	private static $url_segment = 'frontend-admin-settings';
	private static $managed_models = array(ItemList::class);
}
