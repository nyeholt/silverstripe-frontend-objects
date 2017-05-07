<?php

if (!class_exists('FrontEndWorkflowController')) {
	return;
}

class ObjectCreatorPage_FrontEndWorkflowController extends FrontEndWorkflowController {
	private static $allowed_actions = array(
		'index',
		'Form',
		'doEdit',
		'doFrontEndAction',
	);

	public $parentController = null;

	public function __construct() {
		$this->parentController = Controller::curr();
		parent::__construct();
		$this->request = $this->parentController->request;
	}

	public function index($request) {
		if (!Member::currentUser()) {
			 return $this->httpError(404);
		}

		$id = $request->param('Action');
		if ($id == null)
		{
			return $this->parentController->customise(array(
				'Title' => 'Review submissions',
				'CreateForm' => '',
				'Form' => '',
				'IsReviewListing' => true,
			));
		}
		else if ($id)
		{
			$workflowForm = $this->Form();
			if (!$workflowForm)
			{
				return $this->httpError(404);
			}
			$page = $this->getContextObject();
			$title = $page->Title;
			$createTitle = sprintf('Reviewing "%s" submission', $title);
			if ($this->parentController->ReviewWithPageTemplate) {
				$pageController = ModelAsController::controller_for($page);
				return $pageController->customise(array(
					'CreateTitle' => $createTitle,
		        	'CreateForm' => $workflowForm,
		        	'Form' => $workflowForm,
		        ));
			} else {
		        return $this->parentController->customise(array(
		        	'Title' => $createTitle,
		        	'CreateTitle' => $createTitle,
		        	'CreateForm' => $workflowForm,
		        	'Form' => $workflowForm,
		        ));
			}
	    }
	    return $this->httpError(404);
	}

	public function updateFrontendCreateForm($form) {
		//
		// Add 'Go to Review' button 
		//
		$fields = $form->Fields();
		$idField = $fields->dataFieldByName('ID');
		if ($idField 
			&& $this->parentController->CreateType 
			&& ($id = $idField->Value())) 
		{
			// Get Context Object and detect whether we can review/edit with workflows or not
			$contextObj = $this->getContextObjectWithIDAndClass($id, $this->parentController->CreateType);
			if ($contextObj && $contextObj->canEdit())
			{
				$actions = $form->Actions();
				$actions->unshift(FormAction::create('doReview', 'Go to Review'));
			}
		}
	}

	public function updateFrontEndWorkflowActions($fields) {
		$firstField  = $fields->first();
		if (!$firstField instanceof FormAction) {
			// Workaround for this bug on 3.3.1 or lower: https://github.com/silverstripe-australia/advancedworkflow/pull/242
			return;
		}

		if ($this->parentController && !$this->parentController->AllowEditing) {
			// Don't add edit button if can't edit
			return;
		}

		$contextObj = $this->getContextObject();
		if (!$contextObj)
		{
			return;
		}
		if (!$contextObj->canEdit())
		{
			return;
		}

		$fields->unshift(FormAction::create('doEdit', 'Edit'));
	}

	public function Form($request = null) {
		$isExecutingAsAction = ($request && $request instanceof SS_HTTPRequest && $request->param('Action') === __FUNCTION__);
		
		$contextObj = $this->getContextObject();
		if (!$contextObj)
		{
			if ($isExecutingAsAction) 
			{
				user_error('Cannot determine "getContextObject".', E_USER_WARNING);
			}
			return '';
		}

		if (!$contextObj->hasExtension('WorkflowApplicable'))
		{
			user_error($contextObj->ClassName.' does not have "WorkflowApplicable" extension.', E_USER_WARNING);
			return '';
		}
		$workflow = $contextObj->getWorkflowInstance();
		if (!$workflow)
		{
			user_error('No workflow instance on page.', E_USER_WARNING);
			return '';
		}
		if (!$contextObj->canEdit())
		{
			user_error('Current user does not have edit permissions to review this page.', E_USER_WARNING);
			return '';
		}

		// NOTE(Jake): Extend both hooks for 3.3.1 support and below to fix this bug:
		//			   https://github.com/silverstripe-australia/advancedworkflow/pull/242
		$this->beforeExtending('updateFrontEndWorkflowFields', array($this, 'updateFrontEndWorkflowActions'));
		$this->beforeExtending('updateFrontEndWorkflowActions', array($this, 'updateFrontEndWorkflowActions'));
		
		// Force actions to be hidden with 'hide_disabled_actions_on_frontend'
		$prevConfig = WorkflowInstance::config()->hide_disabled_actions_on_frontend;
		WorkflowInstance::config()->hide_disabled_actions_on_frontend = true;
		$form = parent::Form();
		WorkflowInstance::config()->hide_disabled_actions_on_frontend = $prevConfig;

		if (!$form) {
			return '';
		}

		$this->parentController->editObject = $contextObj; // Set 'editObject' for 'CreateForm' function.

		$objFields = null;
		if ($contextObj->hasMethod('getFrontendCreateReviewFields')) {
			$objFields = $contextObj->getFrontendCreateReviewFields();
		} else {
			$objFields = $this->parentController->CreateForm();
			$objFields = $objFields->Fields();
		}

		// Add fields from frontend fields and make them readonly
		$fields = $form->Fields();
		$firstField = $fields->first();
		$firstFieldName = ($firstField) ? $firstField->getName() : '';
		if ($workflow && $workflow->CurrentAction) {
			$fields->insertBefore(ReadonlyField::create('WorkflowCurrentAction_Title', _t('AdvancedWorkflowAdmin.WorkflowStatus', 'Current action'), $workflow->CurrentAction), $firstFieldName);
		}
		foreach ($objFields as $field) {
			if ($field instanceof ListboxField) {
				// NOTE(Jake): Add '_Readonly' as the name allows for ListboxField to show its data properly in readonly mode.
				// NOTE(Jake): Used to add '_Readonly' to every field but that caused 'FrontendWorkflowForm' to not validate.
				$field->setName($field->getName().'_Readonly');
				// NOTE(Jake): Fixes issue where LookupField validates false when blank. (not ideal for ListboxField)
				$field->setHasEmptyDefault(true);
			}
			$field = $field->performReadonlyTransformation();
			$fields->insertBefore($field, $firstFieldName);
		}
		$form->loadDataFrom($contextObj);
		$this->extend('updateFrontEndWorkflowForm', $form);

		$reviewID = (int)$this->request->param('ID');
		if ($reviewID) {
			return $form->httpSubmission($this->request);
		}
		return $form;
	}

	public function doEdit(array $data, Form $form, SS_HTTPRequest $request) {
		$id = (int)$request->param('ID');
		if (!$id) {
			user_error('Invalid ID passed for edit action');
			$this->redirectBack();
		}
		return $this->redirect(Controller::join_links($this->parentController->Link('edit'), $id));
	}

	public function doFrontEndAction(array $data, Form $form, SS_HTTPRequest $request) {
		parent::doFrontEndAction($data, $form, $request);
		return $this->redirect($this->parentController->Link('review'));
	}

	/**
	 * Get a context object with custom ID and ClassName. To be used
	 * with 'singleton()' pattern.
	 */
	public function getContextObjectWithIDAndClass($id, $className) {
		$result = null;
		if ($id && $className) {
			$prevMode = Versioned::get_reading_mode();
			Versioned::set_reading_mode("Stage.Stage");

			$result = DataObject::get_by_id($className, $id);

			Versioned::set_reading_mode($prevMode);
		}
		return $result;
	}

	/**
	 * Get record based on ObjectCreatorPage::CreateType (ClassName) and the ID
	 * sent in request.
	 */
	public function getContextObject() {
		if ($this->contextObj) {
			return $this->contextObj;
		}
		return $this->contextObj = $this->getContextObjectWithIDAndClass($this->getContextID(), $this->getContextType());
	}

	/**
	 * Get ID to query with in getContextObject
	 */
	protected function getContextID() {
		$result = (int)$this->request->param('Action');
		if ($result) {
			return $result;
		}
		return (int)$this->request->param('ID');
	}

	/**
	 * Get ClassName to query with in getContextObject
	 */
	public function getContextType() {
		return $this->parentController->CreateType;
	}

	public function getWorkflowDefinition() {
		// NOTE(Jake): Required to exist because it's an 'abstract function'
		throw new Exception('This function is seemingly not called or used anywhere.');
	}

	public function Link($action = null){
		return Controller::join_links($this->parentController->Link(), 'review', $action);
	}
}