<?php

/**
 * Allow for the mapping of formsubmission fields to 
 * properties to be displayed in tables
 *
 * @author marcus
 */
class SubmittedFormForListExtension extends DataExtension {
	
	/**
	 * 
	 * @param array $fields
	 */
	public function updateSummaryFields(&$fields) {
		foreach ($this->owner->Values() as $field) {
			$fields[$field->Name] = $field->Title;
		}
	}
	
	/**
	 * Add in the form values against the submitted form object
	 * 
	 * @param array $formatting
	 */
	public function updateItemTableFormatting(&$formatting) {
		foreach ($this->owner->Values() as $field) {
			$fieldVal = $field->Value;
			$this->owner->{$field->Name} = $fieldVal;
		}
	}
}
