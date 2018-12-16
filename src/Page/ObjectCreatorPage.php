<?php

namespace Symbiote\FrontendObjects\Page;

use Page;

use Object;

use Symbiote\MultiValueField\Fields\MultiValueTextField;

use SilverStripe\Core\Extensible;

use Symbiote\FrontendObjects\Extension\FrontendCreateableExtension;

use SilverStripe\Assets\File;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowDefinition;
use SilverStripe\Assets\Folder;
use Symbiote\FrontendObjects\Page\FrontendCreateableObject;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\Forms\TreeMultiselectField;
use Symbiote\AdvancedWorkflow\Extensions\WorkflowApplicable;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextField;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Member;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use SilverStripe\ORM\ArrayList;
use Symbiote\AdvancedWorkflow\Controllers\FrontEndWorkflowController;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use SilverStripe\ORM\DataObject;
use SilverStripe\Control\Director;
use SilverStripe\i18n\i18n;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Core\Config\Config;
use Symbiote\FrontendObjects\Page\ObjectCreatorPage;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\Form;
use Symbiote\FrontendObjects\Control\ObjectCreatorPage_FrontEndWorkflowController;
use SilverStripe\ORM\ValidationException;
use Symbiote\AdvancedWorkflow\Services\WorkflowService;
use SilverStripe\Control\Controller;
use PageController;
use Symbiote\MultiValueField\Fields\KeyValueField;



/**
 * A page type that lets users create other data objects from the frontend of
 * their website.
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class ObjectCreatorPage extends Page {

	private static $createable_types = array('Page', File::class);
	private static $db = array(
		'CreateType' => 'Varchar(32)',
		'CreateLocationID' => 'Int',
		'RestrictCreationTo' => 'Varchar(255)',
		'AllowUserSelection' => 'Boolean',
		'CreateButtonText' => 'Varchar',
		'PublishOnCreate' => 'Boolean',
		'ShowCmsLink' => 'Boolean',
		'WhenObjectExists' => "Enum('Rename, Replace, Error', 'Rename')",
		'AllowUserWhenObjectExists' => 'Boolean',
		'SuccessMessage' => 'HTMLText',
		'EditingSuccessMessage' => 'HTMLText',
		'AllowEditing' => 'Boolean',
		'AdditionalProperties'		=> 'MultiValueField',
		'ReviewWithPageTemplate'	=> 'Boolean',
	);
	private static $has_one = array(
		'WorkflowDefinition' => WorkflowDefinition::class
	);
	private static $defaults = array(
		'CreateButtonText' => 'Create',
		'PublishOnCreate' => true
	);

	private static $icon = 'frontend-objects/images/objectcreatorpage.png';

	/**
	 * A mapping between object create type and the type of parent
	 * that it should be created under (if applicable)
	 *
	 * @var array
	 */
	private static $parent_map = array(
		'File' => Folder::class
	);

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$types = ClassInfo::implementorsOf(FrontendCreateableObject::class);

		if (!$types) {
			$types = array();
		}

		$types = array_merge($types, $this->config()->createable_types);
		$types = array_combine($types, $types);

		$fields->addFieldToTab('Root.Main', new DropdownField('CreateType', _t('FrontendCreate.CREATE_TYPE', 'Create objects of which type?'), $types), 'Content');
		$fields->addFieldToTab('Root.Main', new CheckboxField('PublishOnCreate', _t('FrontendCreate.PUBLISH_ON_CREATE', 'Publish after creating (if applicable)')), 'Content');
		$fields->addFieldToTab('Root.Main', new CheckboxField('ShowCmsLink', _t('FrontendCreate.SHOW_CMS_LINK', 'Show CMS link for Page objects after creation')), 'Content');
		$fields->addFieldToTab('Root.Main', new CheckboxField('AllowEditing', _t('FrontendCreate.ALLOW_EDITING', 'Allow frontend editing of this page after creation')), 'Content');
		$fields->addFieldToTab('Root.AfterSubmission', HTMLEditorField::create('SuccessMessage', 'Success Message displayed after new object creation')->setRightTitle('Use $Link and $Title to reference the created page'));
		$fields->addFieldToTab('Root.AfterSubmission', HTMLEditorField::create('EditingSuccessMessage', 'Success Message dislpayed after editing existing object')->setRightTitle('Use $Link and $Title to reference the created page'));

		if ($this->CreateType) {
			if (Extensible::has_extension($this->CreateType, Hierarchy::class)) {
				$parentType = $this->ParentMap();

				if (!$this->AllowUserSelection) {
					$fields->addFieldToTab('Root.Main', new TreeDropdownField('CreateLocationID', _t('FrontendCreate.CREATE_LOCATION', 'Create new items where?'), $parentType), 'Content');
					$fields->addFieldToTab('Root.Main', new CheckboxField('AllowUserSelection', _t('FrontendCreate.ALLOW_USER_SELECT', 'Allow users to select where to create items')), 'Content');
				} else {
					$fields->addFieldToTab('Root.Main', new CheckboxField('AllowUserSelection', _t('FrontendCreate.ALLOW_USER_SELECT', 'Allow users to select where to create items')), 'Content');
					$fields->addFieldToTab('Root.Main', $ts = TreeMultiselectField::create('RestrictCreationToItems', _t('FrontendCreate.RESTRICT_LOCATION', 'Restrict creation to beneath this location'), $parentType), 'Content');
					$ts->setValue($this->RestrictCreationTo);
				}
			}
			if (Extensible::has_extension($this->CreateType, WorkflowApplicable::class)) {
				$workflows = WorkflowDefinition::get()->map()->toArray();
				$fields->addFieldToTab('Root.Main', DropdownField::create('WorkflowDefinitionID', 'Workflow Definition', $workflows)->setHasEmptyDefault(true), 'Content');
				$fields->addFieldToTab('Root.Main', CheckboxField::create('ReviewWithPageTemplate', 'Make Workflow review page render with page template?')
						->setDescription('Renders the page with its controller and shows how it would appear when published. $Form or $CreateForm must exist in the template for the review fields and save buttons.'), 'Content');
			}
		} else {
			$fields->addFieldToTab('Root.Main', new LiteralField('SaveNotice', _t('FrontendCreate.SAVE_NOTICE', '<p>Select a type to create and save the page for additional options</p>')), 'Content');
		}

		$fields->addFieldToTab('Root.Main', KeyValueField::create('AdditionalProperties', 'Extra properties to set on created items'), 'Content');

		$fields->addFieldToTab('Root.Main', new TextField('CreateButtonText', _t('FrontendCreate.UPLOAD_TEXT', 'Upload button text')), 'Content');

		if ($this->useObjectExistsHandling()) {
			$fields->addFieldToTab('Root.Main', new DropdownField('WhenObjectExists', 'When Object Exists', $this->dbObject('WhenObjectExists')->EnumValues()), 'Content');
			$fields->addFieldToTab('Root.Main', new CheckboxField('AllowUserWhenObjectExists', _t('FrontendCreate.ALLOW_USER_WHEN_OBJECT_EXISTS', 'Allow users to select an action to take if the object already exists')), 'Content');
		}

		$this->extend('updateObjectCreatorPageCMSFields', $fields);

		return $fields;
	}

	public function onBeforeWrite() {
		parent::onBeforeWrite();

		if (isset($_REQUEST['ClearCreateLocation'])) {
			$this->CreateLocationID = 0;
		}
	}

	public function onChangeRestrictCreationToItems($items) {
		$this->RestrictCreationTo = implode(',', $items);
		return false;
	}

	public function ParentMap() {
		$parentMap = $this->config()->parent_map;
		if (isset($parentMap[$this->CreateType])) {
			$parentType = $parentMap[$this->CreateType];
		} else {
			$parentType = $this->CreateType;
			$baseClass = ClassInfo::baseDataClass($parentType);
			if ($baseClass && $baseClass === SiteTree::class) {
				$parentType = SiteTree::class;
			}
		}
		return $parentType;
	}

	public function RestrictCreationToItems() {
		$parentType = $this->ParentMap();

		$items = explode(',', $this->RestrictCreationTo);
		$list = DataList::create($parentType)->filter('ID', $items);
		return $list;
	}

	/**
	 * Return link for the review listing page
	 */
	public function LinkReview() {
		if (!$this->canReview(Member::currentUser(), null))
		{
			return '';
		}
		return $this->Link('review');
	}

	/**
	 * Returns all viewable and editable items that are waiting
	 * to be approved.
	 *
	 * @return ArrayList
	 */
	protected $_cache_review_items = null;
	public function ReviewItems() {
		if ($this->_cache_review_items) {
			return $this->_cache_review_items;
		}
		$workflowDef = $this->WorkflowDefinition();
		$member = Member::currentUser();
		if (!$member || !$workflowDef || !$workflowDef->exists()) {
			return;
		}

		if (!$this->canReview($member, null))
		{
			return;
		}

		$workflowInstances = WorkflowInstance::get()->filter(array(
			'DefinitionID' => $this->WorkflowDefinitionID,
			'WorkflowStatus:not' => 'Complete',
		))->sort('LastEdited', 'DESC');

		$result = array();
		foreach ($workflowInstances as $workflowInstance)
		{
			$page = $workflowInstance->Target();
			if (!$page || !$page->exists())
			{
				continue;
			}
			$canEdit = $page->canEdit();
			$canView = $workflowInstance->canView();

			if ($canView || $canEdit)
			{
				$page->_canView = $canView;
				$page->_canEdit = $canEdit;

				// Added CurrentActionSort so that reviewable items are sorted from most approved to least approved.
				$page->_CurrentActionSort = 0;
				$workflowCurrentAction = $workflowInstance->CurrentAction();
				if ($workflowCurrentAction
					&& ($workflowBaseAction = $workflowCurrentAction->BaseAction()))
				{
					$page->_CurrentActionSort = $workflowInstance->CurrentAction()->BaseAction()->Sort;
				}

				if ($canEdit)
				{
					// In the case that the 'FrontendCreateableExtension' isn't applied, setup the
					// review/edit links automatically based on this context.
					if (!$page->FrontendReviewLink) {
						$page->FrontendReviewLink = $this->Link('review/'.$page->ID);
					}
					if ($this->data()->AllowEditing && !$page->FrontendEditLink)
					{
						$page->FrontendEditLink = $this->Link('edit/'.$page->ID);
					}
				}
				$page->WorkflowInstance = $workflowInstance; // Explicitly access workflow instance on templates
				$page->failover = $workflowInstance; // Fallback to Workflow data in templates
				$result[] = $page;
			}
		}
		$result = new ArrayList($result);
		return $this->_cache_review_items = $result;
	}

	/**
	 * Items the user may have previously reviewed but are no longer editable by them.
	 *
	 * @return ArrayList
	 */
	public function ReviewItemsViewable() {
		$reviewItems = $this->ReviewItems();
		if (!$reviewItems) {
			return;
		}
		$result = array();
		foreach ($reviewItems as $page)
		{
			if ($page && $page->_canView && !$page->_canEdit)
			{
				$result[] = $page;
			}
		}
		return new ArrayList($result);
	}

	/**
	 * Items the user the user can review and approve
	 *
	 * @return ArrayList
	 */
	public function ReviewItemsEditable() {
		$reviewItems = $this->ReviewItems();
		if (!$reviewItems) {
			return;
		}
		$result = array();
		foreach ($reviewItems as $page)
		{
			if ($page && $page->_canEdit)
			{
				$result[] = $page;
			}
		}
		$result = new ArrayList($result);
		$result = $result->sort(array(
			'_CurrentActionSort' => 'DESC',
		));
		return $result;
	}

	/**
	 * checks to see if the object being created has the objectExists() method
	 * which is needed to check for existing object
	 * @return bool
	 * */
	public function useObjectExistsHandling() {
		if ($this->CreateType) {
			return singleton($this->CreateType)->hasMethod('objectExists');
		}
	}

	/**
	 * Check whether the member can review submissions or not
	 *
	 * @param Member $member
	 * @param Record $record Check if the user canReview() the record, if 'null' is supplied, check if user
	 *						 can see the review listing page.
	 * @return bool|null
	 */
	public function canReview($member, $record) {
		$extended = $this->extendedCan(__FUNCTION__, $member);
		if($extended !== null) return $extended;

		if (!class_exists(FrontEndWorkflowController::class)) {
			// Cannot review if there's no workflow module installed
			return false;
		}

		// NOTE(Jake): Might *want* to update the below logic so that when "$record === null"
		//			   the user must belong in the Restricted User/Group.
		if (!$this->WorkflowDefinitionID) {
			// Cannot review if there's no workflow applied to the object creator page.
			return false;
		}

		if ($record && !$record->getWorkflowInstance()) {
			// Cannot review if there's no workflow active on the record.
			return false;
		}

		if (!$member) {
			// Cannot review if not a logged in member.
			return false;
		}

		if (Permission::check('ADMIN', 'any', $member)) {
			return true;
		}

		if ($record) {
			$extended = $record->canEdit($member);
			if($extended !== null) return $extended;
		}
		return true;
	}
}
