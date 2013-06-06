<?php

class UserForm extends Form{
	
	protected
		$parent,
		$component = "Fields",
		$showclearbutton = false,
		$finishurl,
		$onsave;

	function __construct($controller, $name, $parent){
		$this->controller = $controller;
		$this->parent = $parent;
		parent::__construct(
			$controller, $name,
			$this->getFormFields(),
			$this->getFormActions(),
			$this->getRequiredFields());

		$this->setRedirectToFormOnValidationError(true);
		$data = Session::get("FormInfo.{$name}.data");
		if(is_array($data)) $this->loadDataFrom($data);
		$this->parent->extend('updateForm', $form);
	}

	/*
	*	TODO: include these, where appropriate
	 */
	function jsRequirements(){
		Requirements::javascript(FRAMEWORK_DIR .'/thirdparty/jquery/jquery.js');
		Requirements::javascript('userforms/thirdparty/jquery-validate/jquery.validate.js');
		Requirements::javascript('userforms/javascript/UserForm_frontend.js');
		if($this->HideFieldLabels) Requirements::javascript('userforms/thirdparty/Placeholders.js/Placeholders.min.js');
	}

	/**
	 * Set function to be called on creation of a new submission
	 * @param function $fn anonmyous founction to manipulate new submittedForm with
	 */
	function setOnSave($fn){
		$this->onsave = $fn;
	}

	/**
	 * Where to redirect after after processing is complete
	 * @param string $url
	 */
	function setFinishURL($url){
		$this->finishurl = $url;
	}

	public function getFormFields() {
		$fields = new FieldList();
		$editablefields = $this->parent->{$this->component}();
		if($editablefields->exists()) {
			foreach($editablefields as $editableField) {
				// get the raw form field from the editable version
				$field = $editableField->getFormField();
				if(!$field) break;
				
				// set the error / formatting messages
				$field->setCustomValidationMessage($editableField->getErrorMessage());

				// set the right title on this field
				if($right = $editableField->getSetting('RightTitle')) {
					$field->setRightTitle($right);
				}
				
				// if this field is required add some
				if($editableField->Required) {
					$field->addExtraClass('requiredField');
					
					if($identifier = UserDefinedForm::config()->required_identifier) {
						
						$title = $field->Title() ." <span class='required-identifier'>". $identifier . "</span>";
						$field->setTitle($title);
					}
				}
				// if this field has an extra class
				if($editableField->getSetting('ExtraClass')) {
					$field->addExtraClass(Convert::raw2att(
						$editableField->getSetting('ExtraClass')
					));
				}
				
				// set the values passed by the url to the field
				$request = $this->controller->getRequest();
				if($var = $request->getVar($field->name)) {
					$field->value = Convert::raw2att($var);
				}
				
				$fields->push($field);
			}
		}
		$this->parent->extend('updateFormFields', $fields);
		return $fields;
	}

	public function getFormActions() {
		//$submitText = ($this->SubmitButtonText) ? $this->SubmitButtonText : 
		$submitText = _t('UserDefinedForm.SUBMITBUTTON', 'Submit');
		
		$actions = new FieldList(
			new FormAction("processuserform", $submitText)
		);

		if($this->showclearbutton) {
			$actions->push(new ResetFormAction("clearForm"));
		}
		
		$this->parent->extend('updateFormActions', $actions);
		
		return $actions;
	}

	public function getRequiredFields() {
		$required = new RequiredFields();

		//...

		return $required;
	}


	public function processuserform($data, $form) {

		Session::set("FormInfo.{$this->FormName()}.data",$data);	
		Session::clear("FormInfo.{$this->FormName()}.errors");

		$editablefields = $this->parent->{$this->component}();

		foreach($editablefields as $field) {
			$messages[$field->Name] = $field->getErrorMessage()->HTML();
				
			if($field->Required && $field->CustomRules()->Count() == 0) {
				if(	!isset($data[$field->Name]) ||
					!$data[$field->Name] ||
					!$field->getFormField()->validate($this->validator)
				){
					$this->addErrorMessage($field->Name,$field->getErrorMessage()->HTML(),'bad');
				}
			}
		}
		
		if(Session::get("FormInfo.{$this->FormName()}.errors")){
			Controller::curr()->redirectBack();
			return;
		}
		
		$submittedForm = Object::create('SubmittedForm');
		$submittedForm->SubmittedByID = ($id = Member::currentUserID()) ? $id : 0;
		$submittedForm->ParentID = $this->parent->ID;

		// if saving is not disabled save now to generate the ID
		if(!$this->DisableSaveSubmissions) {
			$submittedForm->write();
		}
		
		$values = array();
		$attachments = array();

		$submittedFields = new ArrayList();
		
		foreach($editablefields as $field) {
			if(!$field->showInReports()) {
				continue;
			}
			
			$submittedField = $field->getSubmittedFormField();
			$submittedField->ParentID = $submittedForm->ID;
			$submittedField->Name = $field->Name;
			$submittedField->Title = $field->getField('Title');
			
			// save the value from the data
			if($field->hasMethod('getValueFromData')) {
				$submittedField->Value = $field->getValueFromData($data);
			} else {
				if(isset($data[$field->Name])) {
					$submittedField->Value = $data[$field->Name];
				}
			}

			if(!empty($data[$field->Name])){
				if(in_array("EditableFileField", $field->getClassAncestry())) {
					if(isset($_FILES[$field->Name])) {
						
						// create the file from post data
						$upload = new Upload();
						$file = new File();
						$file->ShowInSearch = 0;
						try {
							$upload->loadIntoFile($_FILES[$field->Name], $file);
						} catch( ValidationException $e ) {
							$validationResult = $e->getResult();
							$form->addErrorMessage($field->Name, $validationResult->message(), 'bad');
							Controller::curr()->redirectBack();
							return;
						}

						// write file to form field
						$submittedField->UploadedFileID = $file->ID;
						
						// attach a file only if lower than 1MB
						if($file->getAbsoluteSize() < 1024*1024*1){
							$attachments[] = $file;
						}
					}									
				}
			}
			
			if(!$this->DisableSaveSubmissions) {
				$submittedField->write();
			}
	
			$submittedFields->push($submittedField);
		}

		//callback for doing stuff with new submittedForm
		if(is_callable($this->onsave)){
			$this->onsave->__invoke($submittedForm);
		}
		
		//TODO: email users on submit.
		//$this->emailRecipients($submittedFields);
		
		Session::clear("FormInfo.{$this->FormName()}.errors");
		Session::clear("FormInfo.{$this->FormName()}.data");
		
		$referrer = (isset($data['Referrer'])) ? '?referrer=' . urlencode($data['Referrer']) : "";
		$finishurl = $this->finishurl ?
			Controller::join_links($finishurl,$referrer) :
			Controller::join_links($this->controller->Link(), "finished",$referrer);
		return $this->controller->redirect($finishurl);
	}

	function emailRecipients($submittedFields){

		$emailData = array(
			"Sender" => Member::currentUser(),
			"Fields" => $submittedFields
		);

		if($this->FilteredEmailRecipients()) {

			$email = new UserDefinedForm_SubmittedFormEmail($submittedFields); 
			
			if($attachments){
				foreach($attachments as $file) {
					if($file->ID != 0) {
						$email->attachFile(
							$file->Filename, 
							$file->Filename, 
							HTTP::get_mime_type($file->Filename)
						);
					}
				}
			}

			foreach($this->FilteredEmailRecipients() as $recipient) {
				$email->populateTemplate($recipient);
				$email->populateTemplate($emailData);
				$email->setFrom($recipient->EmailFrom);
				$email->setBody($recipient->EmailBody);
				$email->setSubject($recipient->EmailSubject);
				$email->setTo($recipient->EmailAddress);
				
				if($recipient->EmailReplyTo) {
					$email->setReplyTo($recipient->EmailReplyTo);
				}

				// check to see if they are a dynamic reply to. eg based on a email field a user selected
				if($recipient->SendEmailFromField()) {
					$submittedFormField = $submittedFields->find('Name', $recipient->SendEmailFromField()->Name);

					if($submittedFormField && is_string($submittedFormField->Value)) {
						$email->setReplyTo($submittedFormField->Value);
					}
				}
				// check to see if they are a dynamic reciever eg based on a dropdown field a user selected
				if($recipient->SendEmailToField()) {
					$submittedFormField = $submittedFields->find('Name', $recipient->SendEmailToField()->Name);
					
					if($submittedFormField && is_string($submittedFormField->Value)) {
						$email->setTo($submittedFormField->Value);	
					}
				}
				
				$this->extend('updateEmail', $email, $recipient, $emailData);

				if($recipient->SendPlain) {
					$body = strip_tags($recipient->EmailBody) . "\n ";
					if(isset($emailData['Fields']) && !$recipient->HideFormData) {
						foreach($emailData['Fields'] as $Field) {
							$body .= $Field->Title .' - '. $Field->Value .' \n';
						}
					}
					$email->setBody($body);
					$email->sendPlain();
				}
				else {
					$email->send();	
				}
			}
		}
	}

}