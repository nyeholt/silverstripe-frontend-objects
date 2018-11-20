<?php

namespace Symbiote\FrontendObjects\Extension;

use HtmlEditorConfig;

use MultiRecordTransformation;
use MultiRecordField;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\ArrayLib;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\DataExtension;



class ElementalFrontendCreateValidator extends RequiredFields {
	public function php($data) {
		$result = parent::php($data);
		// maybetodo(jake): If Attribute is a required field, validate here. Need to think about how
		// 			   to make attributes required first. (extend MediaAttribute?)
		return $result;
	}
}

class ElementalFrontendCreateExtension extends DataExtension {
	/**
	 * The default fields to show for getFrontendCreateFields.
	 */
	private static $frontend_create_fields = array(
		'Title',
		'Content',
		'ElementArea',
	);

	/**
	 * Get the frontend fields to use with ObjectCreatorPage type
	 * (frontend-objects module)
	 *
	 * @return FieldList
	 */
	public function getFrontendCreateFields() {
		$fields = new FieldList();
		$cmsFields = $this->owner->getCMSFields();
		$whitelist = ArrayLib::valuekey($this->owner->stat('frontend_create_fields'));

		// Set for frontend
		HtmlEditorConfig::set_active('multirecordediting_minimal');

		foreach ($cmsFields->dataFields() as $field) {
			if (isset($whitelist[$field->getName()])) {
				if ($field instanceof GridField) {
					$field = $field->transform(new MultiRecordTransformation);
				}
				if ($field instanceof MultiRecordField) {
					$field->setFieldsFunction(__FUNCTION__, true);
				}
				$fields->push($field);
			}
		}

		return $fields;
	}

	/**
	 * Get validator for frontend fields used for ObjectCreatorPage.
	 * (frontend-objects module)
	 *
	 * @return MediaPageFrontendCreateValidator
	 */
	public function getFrontendCreateValidator() {
		return ElementalFrontendCreateValidator::create();
	}
}
