<?php

/**
 * A page type that lets users create other data objects from the frontend of 
 * their website. 
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class ObjectCreatorPage extends Page {

	private static $createable_types = array('Page', 'File');
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
		'WorkflowDefinition' => 'WorkflowDefinition'
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
		'File' => 'Folder'
	);

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$types = ClassInfo::implementorsOf('FrontendCreateableObject');

		if (!$types) {
			$types = array();
		}

		$types = array_merge($types, $this->config()->createable_types);
		$types = array_combine($types, $types);

		$fields->addFieldToTab('Root.Main', new DropdownField('CreateType', _t('FrontendCreate.CREATE_TYPE', 'Create objects of which type?'), $types), 'Content');
		$fields->addFieldToTab('Root.Main', new CheckboxField('PublishOnCreate', _t('FrontendCreate.PUBLISH_ON_CREATE', 'Publish after creating (if applicable)')), 'Content');
		$fields->addFieldToTab('Root.Main', new CheckboxField('ShowCmsLink', _t('FrontendCreate.SHOW_CMS_LINK', 'Show CMS link for Page objects after creation')), 'Content');
		$fields->addFieldToTab('Root.Main', new CheckboxField('AllowEditing', _t('FrontendCreate.ALLOW_EDITING', 'Allow frontend editing of this page after creation')), 'Content');
		$fields->addFieldToTab('Root.AfterSubmission', new HTMLEditorField('SuccessMessage', 'Success Message displayed after new object creation'));
		$fields->addFieldToTab('Root.AfterSubmission', new HTMLEditorField('EditingSuccessMessage', 'Success Message dislpayed after editing existing object'));

		if ($this->CreateType) {
			if (Object::has_extension($this->CreateType, 'Hierarchy')) {
				$parentType = $this->ParentMap();

				if (!$this->AllowUserSelection) {
					$fields->addFieldToTab('Root.Main', new TreeDropdownField('CreateLocationID', _t('FrontendCreate.CREATE_LOCATION', 'Create new items where?'), $parentType), 'Content');
					$fields->addFieldToTab('Root.Main', new CheckboxField('ClearCreateLocation', _t('FrontendCreate.CLEAR_CREATE_LOCATION', 'Reset location value')), 'Content');
					$fields->addFieldToTab('Root.Main', new CheckboxField('AllowUserSelection', _t('FrontendCreate.ALLOW_USER_SELECT', 'Allow users to select where to create items')), 'Content');
				} else {
					$fields->addFieldToTab('Root.Main', new CheckboxField('AllowUserSelection', _t('FrontendCreate.ALLOW_USER_SELECT', 'Allow users to select where to create items')), 'Content');
					$fields->addFieldToTab('Root.Main', $ts = TreeMultiselectField::create('RestrictCreationToItems', _t('FrontendCreate.RESTRICT_LOCATION', 'Restrict creation to beneath this location'), $parentType), 'Content');
					$ts->setValue($this->RestrictCreationTo);
				}
			}
			if (Object::has_extension($this->CreateType, 'WorkflowApplicable')) {
				$workflows = WorkflowDefinition::get()->map()->toArray();
				$fields->addFieldToTab('Root.Main', DropdownField::create('WorkflowDefinitionID', 'Workflow Definition', $workflows)->setHasEmptyDefault(true), 'Content');
				$fields->addFieldToTab('Root.Main', CheckboxField::create('ReviewWithPageTemplate', 'Make Workflow review page render with page template?')
						->setDescription('Renders the page with its controller and shows how it would appear when published. $Form or $CreateForm must exist in the template for the review fields and save buttons.'), 'Content');
			}
		} else {
			$fields->addFieldToTab('Root.Main', new LiteralField('SaveNotice', _t('FrontendCreate.SAVE_NOTICE', '<p>Select a type to create and save the page for additional options</p>')), 'Content');
		}

		$fields->addFieldToTab('Root.Main', MultiValueTextField::create('AdditionalProperties', 'Extra properties to set on created items'), 'Content');
		
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
			if ($baseClass && $baseClass === 'SiteTree') {
				$parentType = 'SiteTree';	
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
			$canEdit = $workflowInstance->canEditTarget($page); // NOTE: Must be editable 'By Assignee' in the workflow step.
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
	 */
	public function canReview($member, $record) {
		$extended = $this->extendedCan(__FUNCTION__, $member);
		if($extended !== null) return $extended;

		if (!class_exists('FrontEndWorkflowController')) {
			// Cannot review if there's no workflow module installed
			return false;
		}

		if (!$this->WorkflowDefinitionID) {
			// Cannot review if there's no workflow applied to this page.
			return false;
		}

		if (!$member)
		{
			// Cannot review if not a logged in member.
			return false;
		}

		if (Permission::check('ADMIN', 'any', $member)) {
			return true;
		}

		// If the current member is ever assigned somewhere throughout the given workflow, give them access to 
		// view the review page.
		$actions = AssignUsersToWorkflowAction::get()->filter(array('WorkflowDefID' => $this->WorkflowDefinitionID));
		foreach ($actions as $action)
		{
			$members = $action->getAssignedMembers()->map('ID', 'Email');
			if (isset($members[$member->ID]))
			{
				return true;
			}
		}
		return false;
	}
}

class ObjectCreatorPage_Controller extends Page_Controller {
	public static $allowed_actions = array(
		'index',
		'review',
		'edit',
		'CreateForm',
		'createobject',
		'editobject',
		'doReview',
		'EditorToolbar',
	);

	/**
	 * If editing an object - The object currently being edited
	 * @var DataObject
	 */
	public $editObject = null;

	public function init() {
		parent::init();

		// Initialize Edit Object
		$request = $this->getRequest();
		if (!$request->param('ID'))
		{
			$editObjectID = $request->postVar('ID');
			if ($this->CreateType && $editObjectID)
			{
				$origStage = Versioned::current_stage();
		        Versioned::reading_stage('Stage');
				$this->editObject = DataObject::get_by_id($this->data()->CreateType, $editObjectID);
				Versioned::reading_stage($origStage);
			}
		}
	}

	public function index($request) {
		if ($request->requestVar('new')) {
			return $this->customise(array(
				'Title' => 'Success',
				'Content' => $this->SuccessContent(),
				'Form' => ''
			));
		}
		return array();
	}

	public function review($request) {
		$member = Member::currentUser();
		$record = $this->queryEditObject($request);
		if (!$this->canReview($member, $record)) {
			return $this->httpError(404);
		}

		$request->shiftAllParams();
        $request->shift();
		$controller = ObjectCreatorPage_FrontEndWorkflowController::create();

		// Execute action on sub-controller
		$action = $request->param('Action');
		if (is_numeric($action) || !$action) {
			$action = 'index';
		}
		$classMessage = Director::isLive() ? 'on this handler' : 'on class '.get_class($controller);
		if(!$controller->hasAction($action)) {
			if (Director::isDev()) {
				user_error("Action '$action' isn't available $classMessage.");
			}
			return $this->httpError(404, "Action '$action' isn't available $classMessage.");
		}
		if(!$controller->checkAccessAction($action) || in_array(strtolower($action), array('run', 'init'))) {
			if (Director::isDev()) {
				user_error("Action '$action' isn't allowed $classMessage.");
			}
			return $this->httpError(403, "Action '$action' isn't allowed $classMessage.");
		}
		return $controller->handleAction($request, $action);
	}

	public function edit($request) {
		if (!$this->data()->AllowEditing) {
			return $this->customise(array(
					'Title' => 'Item not editable',
					'Content' => '<p>This item is not editable</p>',
					'Form' => '',
					'CreateForm' => ''
			));
		}

        // NOTE: $this->editObject is used inside 'CreateForm'
		$this->editObject = $this->queryEditObject($request);

		if (!$this->editObject) {
			return $this->httpError(404);
		}

		if ($this->editObject->hasExtension('WorkflowApplicable') 
			&& ($workflowDef = $this->editObject->WorkflowDefinition())
			&& ($workflowDef->exists()))
		{
			$canEdit = false;
			$title = 'Item not currently editable';
			$content = '<p>This item is currently going through an approval process and is not currently editable</p>';

			$workflow = $this->editObject->getWorkflowInstance();
			if (!$workflow || !$workflow->exists())
			{
				// If WorkflowDefinition is set but no workflow is active on the page, it must have been already approved
				// so make it use the same permissions that would be used in the CMS.
				$canEdit = $this->editObject->canEdit();
				$title = 'Item not editable';
				$content = '<p>This item has been already approved.</p>';
			}
			else if ($workflow && $workflow->CurrentAction()->canEditTarget($this->editObject))
			{
				$canEdit = true;
			}

			if (!$canEdit)
			{
				return $this->customise(array(
						'Title' => $request->requestVar('edited') ? '' : $title,
						'Content' => $request->requestVar('edited') ? $this->EditingSuccessContent() : $content,
						'Form' => '',
						'CreateForm' => ''
				));
			}
		}
		else
		{
			// If versioned, ensure that the member editing it, created it.
			$canEdit = false;
			if ($this->editObject->has_extension('Versioned')) 
			{
				$memberID = (int)Member::currentUserID();
				$versionedObj = Versioned::get_version($this->data()->CreateType, $id, 1);
				$canEdit = ($memberID && $versionedObj->AuthorID && $memberID === (int)$versionedObj->AuthorID);
				if (!$canEdit)
				{
					user_error('Must have created the '.$this->data()->CreateType.' record to edit it.', E_USER_WARNING);
					return;
				}
			}
			if (!$canEdit && !$this->editObject->canEdit())
			{
				user_error('Current member does not have permission to edit this '.$this->data()->CreateType.' record.', E_USER_WARNING);
				return;
			}
		}

		$content = $request->requestVar('edited') ? $this->EditingSuccessContent() : '';
		$form = $this->CreateForm();
		/*if ($form) {
			$form->loadDataFrom($this->editObject);
		}*/

		return $this->customise(array(
				'Title' => 'Editing ' . $this->editObject->Title,
				'Content' => $content,
				'Form' => $form,
				'CreateForm' => $form
		));
	}

	/**
	 * Return the HTML-editor toolbar (for HtmlEditorField support on frontend)
	 *
	 * @return HtmlEditorField_Toolbar
	 */
	public function EditorToolbar() {
		// todo(jake): move into MultiRecordField as extension?
		if (!HtmlEditorConfig::get_active()->getOption('language')) {
			HtmlEditorConfig::get_active()->setOption('language', i18n::get_tinymce_lang());
		}
		return HtmlEditorField_Toolbar::create($this, __FUNCTION__);
	}

	public function Form() {
		return $this->CreateForm();
	}

	public function CreateForm($request = null) {
		// NOTE(Jake): This is required here so that any HasMany/ManyManyList in the fields uses
		//			   the Staged data rather than _Live. ie. Elemental.
		$originalReadingMode = Versioned::current_stage();
		Versioned::reading_stage('Stage');

		$fields = new FieldList(
			new TextField('Title', _t('FrontendCreate.TITLE', 'Title'))
		);

		if ($this->CreateType) 
		{
			if (!$this->editObject) {
				$class = $this->CreateType;
				$this->editObject = $class::create();
				unset($class);
			}
			if ($this->editObject instanceof FrontendCreatable || $this->editObject->hasMethod('getFrontendCreateFields')) {
				$tFields = $this->editObject->getFrontendCreateFields();
				if ($tFields) {
					// Only override fields if 'getFrontendCreateFields' actually returns something.
					$fields = $tFields;
				}
			}  else if ($this->editObject instanceof Member) {
				$fields = $this->editObject->getMemberFormFields();
			} else  {
				$fields = $this->editObject->getFrontEndFields();
			}
		} 
		else 
		{
			$fields = new FieldList(
				new LiteralField('InvalidType', 'Invalid configuration is incorrectly configured')
			);
		}
		if ($this->editObject && $this->editObject->ID) {
			$fields->push(HiddenField::create('ID', 'ID', $this->editObject->ID));
		}

		// If record doesn't exist.
		if (!$this->editObject || !$this->editObject->exists()) 
		{
			if ($this->data()->AllowUserSelection) {
				$parentMap = Config::inst()->get('ObjectCreatorPage', 'parent_map');
				$parentType = isset($parentMap[$this->CreateType]) ? $parentMap[$this->CreateType] : $this->CreateType;
				$fields->push($tree = DropdownField::create('CreateLocationID', _t('FrontendCreate.SELECT_LOCATION', 'Location'), $parentType));
				$tree->setSource($this->data()->RestrictCreationToItems()->map()->toArray());
			}

			if ($this->data()->useObjectExistsHandling() && $this->data()->AllowUserWhenObjectExists) {
				$fields->push(new DropdownField(
					'WhenObjectExists', _t('FrontendCreate.WHENOBJECTEXISTS', "If $this->CreateType exists"), $this->dbObject('WhenObjectExists')->EnumValues(), $this->data()->WhenObjectExists
				));
			}

			if ($new = $this->NewObject()) {
				$firstFieldName = $fields->first()->getName();

				$title = $new->getTitle();
				if ($this->ShowCmsLink) {
					$fields->insertBefore(new LiteralField('CMSLink', sprintf(_t('FrontendCreate.ITEM_CMS_LINK', '<p><a href="admin/show/%s" target="_blank">Edit %s in the CMS</a></p>'), $new->ID, $title)), $firstFieldName);
				}
			}
		}

		// Actions
		$action = null;
		if ($this->editObject && $this->editObject->exists()) {
			$action = FormAction::create('editobject', 'Save Changes');
		} else {
			$createButtonText = ($this->data()->CreateButtonText) ? $this->data()->CreateButtonText : 'Create';
			$action = FormAction::create('createobject', $createButtonText);
		}
		$actions = FieldList::create($action);

		// validators
		$validator = ($this->editObject && $this->editObject->hasMethod('getFrontendCreateValidator')) ? $this->editObject->getFrontendCreateValidator() : null;

		$form = new Form($this, 'CreateForm', $fields, $actions, $validator);

		if (class_exists('ObjectCreatorPage_FrontEndWorkflowController'))
		{
			$s = singleton('ObjectCreatorPage_FrontEndWorkflowController');
			$s->updateFrontendCreateForm($form);
		}
		$this->extend('updateFrontendCreateForm', $form);
		if ($this->editObject) {
			$this->editObject->invokeWithExtensions('updateFrontendCreateForm', $form);
			if ($this->editObject->exists()) {
				$form->loadDataFrom($this->editObject);
			}
		}

		Versioned::reading_stage($originalReadingMode);
		return $form;
	}

	/**
	 * Callback to handle filtering of the selection tree that users can create in. 
	 * 
	 * Uses extensions to allow for overrides.
	 *
	 * @param DataObject $node 
	 */
	public function createLocationFilter($node) {
		$allow = $this->extend('filterCreateLocations', $node);
		if (count($allow) == 0) {
			return true;
		}
		return min($allow) > 0;
	}

	/**
	 * Return the new object if set in the URL
	 * @return DataObject
	 */
	public function NewObject() {
		$id = (int) $this->request->requestVar('new');
		if ($id) {
			$item = DataObject::get_by_id($this->CreateType, $id);
			if (!$item) {
				$item = Versioned::get_by_stage($this->CreateType, "Stage", "{$this->CreateType}.ID = $id")->First();
			}
			return $item;
		}

		return null;
	}

	/**
	 * Get's the success message and replaces the placeholders with the new objects values
	 * @return string
	 */
	public function SuccessContent() {
		if ($object = $this->NewObject()) {
			$message = $this->Data()->SuccessMessage;
			$message = str_replace('$Title', $object->Title, $message);
			$message = str_replace('$Link', $object->Link('?stage=Stage'), $message);
			return $message;
		}
	}

	/**
	 * Get's the success message and replaces the placeholders with the new objects values
	 * @return string
	 */
	public function EditingSuccessContent() {
		if ($object = $this->editObject) {
			$message = $this->Data()->EditingSuccessMessage;
			$message = str_replace('$Title', $object->Title, $message);
			$message = str_replace('$Link', $object->Link('?stage=Stage'), $message);
			return $message;
		}
	}

	/**
	 *
	 * Action called by the form to actually create a new page object.
	 *
	 * @param SS_HttpRequest $request
	 * @param Form $form
	 */
	public function createobject($data, Form $form, $request) {
		if ($this->data()->AllowUserSelection) {
			$pid = $request->postVar('CreateLocationID');
			$allowedParents = $this->data()->RestrictCreationToItems();
			$mapped = $allowedParents->map()->toArray();
			if (isset($mapped[$pid])) {
				$this->pid = $pid;
			}
		} else {
			$this->pid = $this->data()->CreateLocationID;
		}

		if ($this->data()->AllowUserWhenObjectExists) {
			$this->woe = $request->postVar('WhenObjectExists');
		} else {
			$this->woe = $this->data()->WhenObjectExists;
		}

		// create a new object or update / replace one...
		$obj = null;
		if ($this->data()->useObjectExistsHandling()) 
		{
			$existingObject = $this->objectExists();
			if ($existingObject && $this->woe == 'Replace') 
			{
				if ($existingObject->hasExtension('VersionedFileExtension') || $existingObject->hasExtension('Versioned')) {
					$obj = $existingObject;
				} else {
					$existingObject->delete();
				}
			} 
			elseif ($existingObject && $this->woe == 'Error') 
			{
				$form->sessionMessage("Error: $this->CreateType already exists", 'bad');
				return $this->redirect($this->Link()); // redirect back with error message	
			}
		}
		if (!$obj) {
			// Set $obj to $this->editObject so it's modifying the same entity provided to the form.
			// (Ensures UnsavedRelationList stuff works properly)
			$obj = $this->editObject;
		}

		if ($this->pid) {
			$obj->ParentID = $this->pid;
		}

		$obj->ObjectCreatorPageID = $this->ID;

		if (!$form->validate()) {
			$form->sessionMessage("Could not validate form", 'bad');
			return $this->redirect($this->data()->Link());
		}

		// allow extensions to change the object state just before creating. 
		$this->extend('updateObjectBeforeCreate', $obj);

		if ($obj->hasMethod('onBeforeFrontendCreate')) {
			$obj->onBeforeFrontendCreate($this);
		}

		$origMode = Versioned::get_reading_mode();
		Versioned::reading_stage('Stage');

		try {
			$form->saveInto($obj);
		} catch (ValidationException $ve) {
			Versioned::set_reading_mode($origMode);
			$form->sessionMessage("Could not upload file: " . $ve->getMessage(), 'bad');
			$this->redirect($this->data()->Link());
			return;
		}

		// get workflow
		$workflowID = $this->data()->WorkflowDefinitionID;
		$workflow = false;
		if ($workflowID && $obj->hasExtension('WorkflowApplicable')) {
			if ($workflow = WorkflowDefinition::get()->byID($workflowID)) {
				$obj->WorkflowDefinitionID = $workflowID;
			}
		}

		if (Object::has_extension($this->CreateType, 'Versioned')) {
			// switching to make sure everything we do from now on is versioned, until the
			// point that we redirect
			$obj->write();
			if ($this->PublishOnCreate) {
				$obj->doPublish();
			}
		} else {
			$obj->write();
		}

		// start workflow
		if ($workflow) {
			$svc = singleton('WorkflowService');
			$svc->startWorkflow($obj);
		}

		$this->extend('objectCreated', $obj);
		// let the object be updated directly
		// if this is a versionable object, it'll be edited on stage
		$obj->invokeWithExtensions('frontendCreated');

		Versioned::set_reading_mode($origMode);
		$this->redirect($this->data()->Link() . '?new=' . $obj->ID);
	}

	/**
	 *
	 * Action called by the form to edit the object
	 *
	 * @param array $data
	 * @param Form $form
	 * @param SS_HttpRequest $request
	 */
	public function editobject($data, Form $form, $request) {
		Versioned::reading_stage('Stage');

		if ($form->validate()) {
			// allow extensions to change the object state just before creating. 
			$this->extend('updateObjectBeforeEdit', $this->editObject);

			if ($this->editObject->hasMethod('onBeforeFrontendEdit')) {
				$this->editObject->onBeforeFrontendEdit($this);
			}

			try {
				$form->saveInto($this->editObject);
			} catch (ValidationException $ve) {
				$form->sessionMessage("Could not upload file: " . $ve->getMessage(), 'bad');
				$this->redirect($this->data()->Link());
				return;
			}

			// get workflow
			$workflowID = $this->data()->WorkflowDefinitionID;
			$workflowDef = false;
			if ($workflowID && $this->editObject->hasExtension('WorkflowApplicable')) {
				if ($workflowDef = WorkflowDefinition::get()->byID($workflowID)) {
					$this->editObject->WorkflowDefinitionID = $workflowID;
				}
			}

			if (Object::has_extension($this->CreateType, 'Versioned')) {
				$this->editObject->write('Stage');
				if ($this->PublishOnCreate) {
					$this->editObject->doPublish();
				}
			} else {
				$this->editObject->write();
			}

			// start workflow
			if ($this->editObject->hasExtension('WorkflowApplicable') && ($workflow = $this->editObject->getWorkflowInstance())) 
			{
				if ($workflow->CurrentAction()->canEditTarget($this->editObject))
				{
					$svc = singleton('WorkflowService');
					$workflowForObj = $svc->getWorkflowFor($this->editObject);
					if (!$workflowForObj) {
						// Only start a workflow if not in the middle of one.
						$svc->startWorkflow($this->editObject);
					}
				}
			}

			$this->extend('objectEdited', $this->editObject);
			// let the object be updated directly
			// if this is a versionable object, it'll be edited on stage
			$this->editObject->invokeWithExtensions('frontendEdited');
		} else {
			$form->sessionMessage("Could not validate form", 'bad');
		}

		$this->redirect($this->data()->Link("edit/{$this->editObject->ID}") . '?edited=1');
	}

	/**
	 * Redirect to the page to review
	 */
	public function doReview($data) {
		$id = isset($data['ID']) ? (int)$data['ID'] : null;
		if (!$id) {
			user_error('Invalid ID passed for review action');
			$this->redirectBack();
		}
		return $this->owner->redirect(Controller::join_links($this->owner->Link('review'), $id));
	}

	/**
	 * checks to see if the object being created already exists and if so, returns it
	 *
	 * @return DataObject
	 * */
	public function objectExists() {
		if ($this->data()->useObjectExistsHandling()) {
			return singleton($this->CreateType)->objectExists($this->request->postVars(), $this->pid);
		}
	}

	/** 
	 * @return DataObject
	 */
	protected function queryEditObject($request) {
		$id = (int)$request->param('ID');
		if (!$id || !$this->data()->CreateType) {
			return null;
		}
		$origStage = Versioned::current_stage();
        Versioned::reading_stage('Stage');
		$result = DataObject::get_by_id($this->data()->CreateType, $id);
		Versioned::reading_stage($origStage);
		return $result;
	}
}
