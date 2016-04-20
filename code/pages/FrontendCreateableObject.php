<?php

/**
 * An object that can be created on the frontend
 * 
 * Implementors of this can be created on the frontend. 
 * 
 * The FrontendCreateFields method is used to determine which 
 * fields are displayed when creating an item of this type. 
 * 
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
interface FrontendCreateableObject {
	public function getFrontendCreateFields();

	public function getFrontendCreateValidator();
}
