<?php

namespace Symbiote\FrontendObjects\Page;

use PageController;

use SilverStripe\ORM\DataObject;
use Symbiote\AdvancedWorkflow\Extensions\WorkflowApplicable;
use SilverStripe\Versioned\Versioned;
use Symbiote\FrontendObjects\Page\ObjectCreatorPage;
use Symbiote\FrontendObjects\Control\ObjectCreatorPage_FrontEndWorkflowController;
use Symbiote\AdvancedWorkflow\Services\WorkflowService;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\Form;
use SilverStripe\Core\Extensible;
use SilverStripe\Security\Member;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBField;


class ObjectCreatorPageController extends PageController {
	private static $allowed_actions = array(
		'index',
		'review',
		'edit',
		'CreateForm',
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
        $editObjectID = $request->requestVar('ID');
        if ($this->CreateType && $editObjectID)
        {
            $origStage = Versioned::get_stage();
            Versioned::set_stage('Stage');
            $this->editObject = DataObject::get_by_id($this->data()->CreateType, $editObjectID);
            Versioned::set_stage($origStage);
        }
	}

	public function index($request) {
		if ($request->requestVar('new')) {
			return $this->customise(array(
				'Title' => 'Success',
				'Content' => DBField::create_field('HTMLText', $this->SuccessContent()),
				'Form' => ''
			));
		}
		return array();
	}

	public function review($request) {
		$member = Member::currentUser();
		$record = $this->queryEditObject($request);
		if (!$this->data()->canReview($member, $record)) {
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

		if ($this->editObject->hasExtension(WorkflowApplicable::class)
			&& ($workflowDef = $this->editObject->WorkflowDefinition())
			&& $workflowDef->exists()) {
			$canEdit = $this->editObject->canEdit();
			if (!$canEdit) {
				$title = 'Item not currently editable';
				$content = '<p>This item is currently going through an approval process and is not currently editable</p>';
				$workflow = $this->editObject->getWorkflowInstance();
				if (!$workflow || !$workflow->exists()) {
					$title = 'Item not editable';
					$content = '<p>This item has been already approved.</p>';
				}
				return $this->customise(array(
						'Title' => $request->requestVar('edited') ? '' : $title,
						'Content' => DBField::create_field('HTMLText',$request->requestVar('edited') ? $this->EditingSuccessContent() : $content),
						'Form' => '',
						'CreateForm' => ''
				));
			}
		} else {
			$canEdit = false;
			if ($this->editObject->has_extension(Versioned::class))
			{
				// If versioned, ensure that the member editing it, created it.
				$memberID = (int)Member::currentUserID();
				$versionedObj = Versioned::get_version($this->data()->CreateType, $this->editObject->ID, 1);
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
				'Content' => DBField::create_field('HTMLText',$content),
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
		//
		//			   We *do not* want to revert back to _Live mode before the end of the function
		//			   or has_many/many_many will use the _Live table.
		Versioned::set_stage('Stage');

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
				new LiteralField('InvalidType', 'Type configuration is incorrectly configured')
			);
		}
		if ($this->editObject && $this->editObject->ID) {
			$fields->push(HiddenField::create('ID', 'ID', $this->editObject->ID));
		}

		// If record doesn't exist.
		if (!$this->editObject || !$this->editObject->exists())
		{
			if ($this->data()->AllowUserSelection) {
				$parentMap = Config::inst()->get(ObjectCreatorPage::class, 'parent_map');
				$parentType = isset($parentMap[$this->CreateType]) ? $parentMap[$this->CreateType] : $this->CreateType;
				$fields->push($tree = DropdownField::create('CreateLocationID', _t('FrontendCreate.SELECT_LOCATION', 'Location')));
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

		if (class_exists(ObjectCreatorPage_FrontEndWorkflowController::class))
		{
			$s = singleton(ObjectCreatorPage_FrontEndWorkflowController::class);
			$s->updateFrontendCreateForm($form);
		}
		$this->extend('updateFrontendCreateForm', $form);
		if ($this->editObject) {
			$this->editObject->invokeWithExtensions('updateFrontendCreateForm', $form);
			if ($this->editObject->exists()) {
				$form->loadDataFrom($this->editObject);
			}
		}
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
				$item = Versioned::get_by_stage($this->CreateType, 'Stage')->byID($id);
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
			$message = str_replace('$Link', $object->Link(), $message);
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
				if ($existingObject->hasExtension('VersionedFileExtension') || $existingObject->hasExtension(Versioned::class)) {
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

		// if (!$form->validate()) {
		// 	$form->sessionMessage("Could not validate form", 'bad');
		// 	return $this->redirect($this->data()->Link());
		// }

		// allow extensions to change the object state just before creating.
		$this->extend('updateObjectBeforeCreate', $obj);

		if ($obj->hasMethod('onBeforeFrontendCreate')) {
			$obj->onBeforeFrontendCreate($this);
		}

		$origMode = Versioned::get_reading_mode();
		Versioned::set_stage('Stage');

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
		if ($workflowID && $obj->hasExtension(WorkflowApplicable::class)) {
			if ($workflow = WorkflowDefinition::get()->byID($workflowID)) {
				$obj->WorkflowDefinitionID = $workflowID;
			}
		}

		if (Extensible::has_extension($this->CreateType, Versioned::class)) {
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
			$svc = singleton(WorkflowService::class);
			$svc->startWorkflow($obj);
		}

		$this->extend('objectCreated', $obj);
		// let the object be updated directly
		// if this is a versionable object, it'll be edited on stage
		$obj->invokeWithExtensions('frontendCreated');

        Versioned::set_reading_mode($origMode);
        $link = $this->data()->Link();
        $link = $link . (strpos($link, "?") !== false ? "&" : "?") . "new=" . $obj->ID;
		$this->redirect($link);
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
		Versioned::set_stage('Stage');

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
			if ($workflowID && $this->editObject->hasExtension(WorkflowApplicable::class)) {
				if ($workflowDef = WorkflowDefinition::get()->byID($workflowID)) {
					$this->editObject->WorkflowDefinitionID = $workflowID;
				}
			}

			if (Extensible::has_extension($this->CreateType, Versioned::class)) {
				$this->editObject->write('Stage');
				if ($this->PublishOnCreate) {
					$this->editObject->doPublish();
				}
			} else {
				$this->editObject->write();
			}

			// start workflow
			if ($this->editObject->hasExtension(WorkflowApplicable::class))
			{
				$svc = singleton(WorkflowService::class);
				$workflowForObj = $svc->getWorkflowFor($this->editObject);
				if (!$workflowForObj) {
					// Only start a workflow if not in the middle of one.
					$svc->startWorkflow($this->editObject);
				}
			}

			$this->extend('objectEdited', $this->editObject);
			// let the object be updated directly
			// if this is a versionable object, it'll be edited on stage
			$this->editObject->invokeWithExtensions('frontendEdited');

            $link = $this->data()->Link("edit/{$this->editObject->ID}");
            $link = $link . (strpos($link, "?") !== false ? "&" : "?") . "edited=1";

		$this->redirect($link);
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
		$origStage = Versioned::get_stage();
        Versioned::set_stage('Stage');
		$result = DataObject::get_by_id($this->data()->CreateType, $id);
		Versioned::set_stage($origStage);
		return $result;
	}
}
