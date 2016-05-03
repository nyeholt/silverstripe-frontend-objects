<?php

class MediaPageFrontendCreateValidator extends RequiredFields {
	public function php($data) {
		$result = parent::php($data);
		// maybetodo(jake): If Attribute is a required field, validate here. Need to think about how
		// 			   to make attributes required first. (extend MediaAttribute?)
		return $result;
	}
}

class MediaPageFrontendCreateExtension extends DataExtension {
	/**
	 * Set how jQuery is added to the page for Display Logic fields.
	 *
	 * 'auto' - Add jQuery if not detected. (checks if JS path contains 'jquery')
	 * true   - Use framework jQuery.
	 * false  - Do not include jQuery. Assumes you're using your own from a theme/etc.
	 */
	private static $frontend_create_use_framework_jquery = 'auto';

	/**
	 * The default fields to show for getFrontendCreateFields.
	 */
	private static $frontend_create_fields = array(
		'Title',
		'Content',
		'MediaTypeID',
	);

	/**
	 * Get the frontend fields to use with ObjectCreatorPage type
	 * (frontend-objects module)
	 *
	 * @return FieldList
	 */
	public function getFrontendCreateFields() {
		$fields = $this->owner->scaffoldFormFields();
		$whitelist = ArrayLib::valuekey($this->owner->stat('frontend_create_fields'));
		foreach ($fields as $field) {
			if (!isset($whitelist[$field->getName()])) {
				$fields->remove($field);
			}
		}

		// Update Media Type dropdown to only use available media types
		$mediaTypeField = $fields->dataFieldByName('MediaTypeID');
		if ($mediaTypeField && $mediaTypeField instanceof DropdownField) 
		{
			$mediaTypeField->setSource($this->owner->getFrontEndCreateMediaTypes());
			$mediaTypeField->setHasEmptyDefault(false); // Remove blank option, force user to select one.

			if (class_exists('DisplayLogicFormField'))
			{
				// The display logic module requires jQuery, however sometimes people prefer
				// to roll their own jQuery.js file from their theme, so only use the framework
				// provided jQuery if they HAVE NOT included jquery.
				$use_framework_jquery = $this->owner->config()->frontend_create_use_framework_jquery;
				if ($use_framework_jquery === 'auto')
				{
					$hasjQuery = false;
					foreach (Requirements::backend()->get_javascript() as $filename)
					{
						if (strpos($filename, 'jquery') !== false) 
						{
							$hasjQuery = true;
							break;
						}
					}
					if (!$hasjQuery)
					{
						Requirements::javascript(THIRDPARTY_DIR . '/jquery/jquery.js');
					}
				}
				else if ($use_framework_jquery == false || $use_framework_jquery === 'false')
				{
					// no-op
				}
				else if ($use_framework_jquery == 1) 
				{
					Requirements::javascript(THIRDPARTY_DIR . '/jquery/jquery.js');
				}
				else
				{
					throw new Exception($this->owner->class.'::frontend_create_use_framework_jquery has been configured improperly.');
				}
			}
		}

		// Add attribute fields
		$this->owner->updateCMSAttributeFields($fields);

		return $fields;
	}

	/**
	 * Fields to show the user in the review process, removes any attribute
	 * fields that don't match with the current set MediaType ID.
	 * (frontend-objects module)
	 *
	 * @return FieldList
	 */
	public function getFrontendCreateReviewFields() {
		$fields = $this->owner->getFrontendCreateFields();
		foreach ($fields as $field)
		{
			$attribute = $field->AttributeRecord;
			if ($attribute && $attribute->MediaTypeID != $this->owner->MediaTypeID) 
			{
				$fields->remove($field);
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
		return MediaPageFrontendCreateValidator::create();
	}

	/**
	 * Only gets Media Types that have been set on MediaHolder pages
	 *
	 * @return array
	 */
	public function getFrontEndCreateMediaTypes(FieldList $fields = null) {
		$mediaTypeIDs = array();
		foreach (MediaHolder::get() as $holderPage)
		{
			if ($holderPage->MediaTypeID && !isset($mediaTypeIDs[$holderPage->MediaTypeID]) && ($mediaType = $holderPage->MediaType())) 
			{
				$mediaTypeIDs[$holderPage->MediaTypeID] = $mediaType->Title;
			}
		}
		// MAYBETODO(Jake): Add ->extend for modifying the media types
		return $mediaTypeIDs;
	}
}