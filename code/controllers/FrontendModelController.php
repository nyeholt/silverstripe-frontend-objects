<?php

/**
 * A Model controller for working with objects on the frontend
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/BSD-license
 */
class FrontendModelController extends Page_Controller {
	
	private static $allowed_actions = array(
		'view',
		'index',
		'all',
		'showlist',
		'edit',
		'save',
		'CreateForm',
		'EditForm',
	);

	private static $url_handlers = array(
		'model/$ModelClass/$Action/$ID' => 'handleAction',
		'$Action/$ID/$OtherID' => 'handleAction',
	);

	protected $record;
	
	protected $modelClass = null;

	public function index() {
		if (!$this->redirectedTo()) {
			return $this->redirect('');
		}
	}
	
	public function handleAction($request, $action) {
		$this->record = $this->getRecord();
		$id = (int) $this->request->param('ID');
		if ($id && !$this->record) {
			return Security::permissionFailure($this, "You do not have permission to that");
		}
		return parent::handleAction($request, $action);
	}

	public function view() {
		if ($this->record) {
			return $this->customise($this->record)->renderWith(array($this->modelClass(), 'Page'));
		}
		
		throw new Exception("Invalid record");
	}
	
	public function all() {
		if (!Member::currentUserID()) {
			Security::permissionFailure($this, "You must be logged in");
			return;
		}
		return $this->renderWith(array($this->modelClass().'_list', 'Page'));
	}
	
	/**
	 * Shows a specific item list
	 */
	public function showlist() {
		if ($this->getRecord() && $this->getRecord() instanceof ItemList) {
			$content = $this->getRecord()->forTemplate();
			if ($this->request->isAjax()) {
				return $content;
			} else {
				return $this->customise(array(
					'Content' => $content
				))->renderWith(array('Page', 'Page'));
			}
		}
	}

	public function edit() {
		if (!Member::currentUserID()) {
			Security::permissionFailure($this, "You must be logged in");
			return;
		}
		if ($this->record && !$this->record->canEdit()) {
			Security::permissionFailure($this, "You must be logged in to edit that");
			return;
		}
		if($this->request->isAjax()) {
			return $this->Form()->forAjaxTemplate();
		} else {
			if($this->record) {
				$controller = $this->customise($this->record);
			} else {
				$controller = $this;
			}

			return $controller->renderWith(array(
				$this->modelClass() . '_edit', 'FrontendRecord_edit', 'Page'
			));
		}
	}

	public function Form() {
		return $this->EditForm();
	}

	public function EditForm() {
		$object = $this->getRecord();
		if (!$object) {
			$object = singleton($this->modelClass());
		}

		$fields = $object->getFrontEndFields();
		/* @var $fields FieldList */
		$df = $fields->dataFields();
		
		foreach ($df as $formField) {
			// change the template for fieldholders
			$formField->setFieldHolderTemplate('FrontendFormField_holder');
			if ($formField instanceof DatetimeField) {
				$formField->getDateField()->setFieldHolderTemplate('FrontendFormField_holder');
				$formField->getTimeField()->setFieldHolderTemplate('FrontendFormField_holder');
			}
		}
		
		$actions = new FieldList(
			$button = new FormAction('save', _t('Dashboards.SAVE', 'Save'))
		);
		$button->addExtraClass('button');

		$validator = new RequiredFields('Title');

		$form = new Form($this, 'EditForm', $fields, $actions, $validator);
		
		if ($this->record) {
			$form->Fields()->push(new HiddenField('ID', '', $this->record->ID));
			$form->loadDataFrom($this->record);
		}

		return $form;
	}

	public function save($data, Form $form, $request) {
		
		// if there's no existing id passed in the request, we must assume we're
		// creating a new one, so chec kthat it doesn't exist already.
		if (!$this->record) {
			if (!Member::currentUserID()) {
				Security::permissionFailure($this, "You must be logged in");
				return;
			}
			$existing = singleton('DataService')->getOne($this->modelClass(),array('Title' => $this->request->requestVar('Title')));
			if ($existing) {
				throw new Exception("Record already exists");
			}
			
			$cls = $this->modelClass();
			$this->record = $cls::create();
		}

		if (!$this->record->canEdit()) {
			return $this->httpError(403);
		}

		$form->saveInto($this->record);
		$this->record->write();
		if ($this->request->isAjax()) {
			$this->response->addHeader('Content-type', 'application/json');
			return json_encode(array(
				'message' => 'success', 
				'class' => $this->record->ClassName, 
				'id' => $this->record->ID, 
				'form' => $this->EditForm()->forTemplate()->raw()
			));
		} else {
			$this->redirect($this->Link('edit', $this->record));
		}
	}

	public function Record() {
		return $this->record;
	}
	
	protected function modelClass() {
		if ($this->modelClass) {
			return $this->modelClass;
		}
		$request = $this->request->requestVar('model');
		
		if (!$request) {
			// check URL params
			$request = $this->request->param('ModelClass');
		}
		
		if ($request) {
			$allowed = self::config()->allowed_classes;
			if (!in_array(strtolower($request), $allowed)) {
				$request = null;
			} else {
				// see if there's actually a class
				if (class_exists($request)) {
					$cls = new ReflectionClass($request);
					$request = $cls->getName();
				}
			}
		}
		
		$specified = self::config()->model_class;
		if ($specified) {
			$request = $specified;
		}
		
		if (!$request) {
			throw new Exception("Invalid modeltype specified");
		}
		$this->modelClass = $request;
		return $request;
	}

	protected function getRecord() {
		if ($this->record) {
			return $this->record;
		}
		$id = (int) $this->request->param('ID'); 
		if (!$id) {
			$id = (int) $this->request->requestVar('ID');
		}
		if ($id) {
			return DataList::create($this->modelClass())->restrictedByID($id); 
		}
	}
	
	public function Link($action='') {
		$record = null;
		$args = func_get_args();
		if (count($args) == 2) {
			$record = $args[1];
		}
		
		
//		return Controller::join_links(Director::baseURL(), strtolower($this->modelClass()), $action);
		if ($record) {
			return Controller::join_links(Director::baseURL(), 'frontend-admin', 'model', strtolower($record->ClassName), $action, $record->ID);
		} else {
			return Controller::join_links(Director::baseURL(), 'frontend-admin', 'model', strtolower($this->modelClass()), $action);
		}
	}
	
	
	protected function checkSecurityID($request) {
		$secId = $request->postVar(SecurityToken::inst()->getName());
		if ($secId != SecurityToken::inst()->getValue()) {
			Security::permissionFailure($this);
			return false;
		}
		return true;
	}
}
