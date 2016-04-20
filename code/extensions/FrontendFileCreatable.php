<?php

/**
 * Frontend file create extension used to allow file uploads to be created from the frontend. 
 * 
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class FrontendFileCreatable extends DataExtension {
	

	protected $creatorPage;


	public function getFrontendCreateFields() {
		$fields = new FieldList(
			new TextField('Title', 'Title'),
			new FileField('FileUpload', 'Select File')
		);

		// required for VersionedFileExtension::saveReplacementFile() to work
		if($this->owner->hasExtension('VersionedFileExtension')){
			$fields->push(new HiddenField('Replace', 'Replace', 'upload'));
		}

		$this->owner->extend('updateFrontendCreateFields', $fields);
		return $fields;
	}


	public function saveFileUpload($tmpFile) {
		if ($tmpFile['error']) {
			return;
		}

		$folder 	= DataObject::get_by_id('Folder', ((int) $this->creatorPage->pid));
		$folderPath = $folder && $folder->ID ? rtrim(substr($folder->Filename, 7), '/') : '';

		if($existingFile = $this->creatorPage->objectExists()){
			if($this->creatorPage->woe == 'Replace' && $existingFile->hasExtension('VersionedFileExtension')){	
				//save a new version if we have the versioned files module
				$existingFile->saveReplacementFile($tmpFile);
				return;
			}
		}

		// do the standard upload
		$upload = new Upload();
		$upload->loadIntoFile($tmpFile, $this->owner, $folderPath);
		if($upload->isError()) {
			throw new Exception("Error uploading file");
		}
	}


	public function onBeforeFrontendCreate($creatorPage){
		$this->creatorPage = $creatorPage;
	}


	public function objectExists($post, $pid){
		$pid 		= $pid ? $pid : 0;
		$base 		= Director::baseFolder();
		$folder 	= DataObject::get_by_id('Folder', $pid);
		$folderPath = $folder && $folder->ID ? rtrim(substr($folder->Filename, 7), '/') : '';
		$tmpFile 	= $post['FileUpload'];

		$fileName 	= str_replace(' ', '-',$tmpFile['name']);
		$fileName 	= ereg_replace('[^A-Za-z0-9+.-]+','',$fileName);
		$fileName 	= ereg_replace('-+', '-',$fileName);
		$fileName 	= basename($fileName);
		$relativeFilePath = ASSETS_DIR . "/" . $folderPath . "/$fileName";

		if(file_exists("$base/$relativeFilePath") && $file = DataObject::get_one('File', "Filename = '$relativeFilePath' AND ParentID = $pid")){
			return $file;
		}
	}
			
}