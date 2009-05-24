<?php
/**
 * Publish Controller
 * 
 * Manages new postings
 *
 * @author Valentin Bora <contact@valentinbora.com>
 * @version 1.0
 * @package Joobsbox_Controller
 */
 
/**
 * @package Joobsbox_Controller
 */
class PublishController extends Zend_Controller_Action 
{
	public $form;
	public function indexAction() 
    { 
		/*
		 *
		 *	@todo email site admin when inserted
		 *
		 */
		$this->_model = new Joobsbox_Model_Jobs;
		
		// <createForm>
        $form = new Zend_Form;
		$form->setAction($_SERVER['REQUEST_URI'])->setMethod('post')->setAttrib("id", "formPublish");
	
		$title = $form->createElement('text', 'title')
			->setLabel('Job title:')
			->addFilter('StripTags')
			->addFilter('StringTrim')
			->addFilter('HtmlEntities')
			->addValidator('notEmpty')
			->setDescription('Ex: "Flash Designer" or "ASP.NET Programmer"')
			->setRequired(true);
			
		$company = $form->createElement('text', 'company')
			->setLabel('Company:')
			->addFilter('StripTags')
			->addFilter('StringTrim')
			->addFilter('HtmlEntities')
			->addValidator('notEmpty')
			->setRequired(true);
		$location = $form->createElement('text', 'location')
			->setLabel('Location:')
			->addFilter('StripTags')
			->addFilter('StringTrim')
			->addFilter('HtmlEntities')
			->addValidator('notEmpty')
			->setDescription('Ex: "Iaşi, Bucureşti"')
			->setRequired(true);
			
		$categories = $this->_model->fetchCategories()->getIndexNamePairs();
		array_unshift($categories, $this->view->translate("Choose..."));
		
		$category = $form->createElement('select', 'category')
			->setLabel('Category:')
			->addValidator('notEmpty')
			->setRequired(true)
			->setMultiOptions($categories);
			
		$description = $form->createElement('textarea', 'description')
			->setLabel('Job description:')
			->setDescription('HTML code is not accepted. Length must be less than 4000 characters.')
			->addFilter('StripTags')
			->addFilter('StringTrim')
			->addValidator('notEmpty')
			->setRequired(true);
		
		$application = $form->createElement('text', 'application')
			->setLabel('Means of application:')
			->addFilter('StripTags')
			->addFilter('StringTrim')
			->addValidator('notEmpty')
			->setDescription('Ex: "Send CV to email ..." or "Apply online at URL ..."')
			->setRequired(true);
		
		$submit = $form->createElement('submit', 'submit')
			->setLabel("Add");
			
		$publishNamespace = new Zend_Session_Namespace('PublishJob');
		if(isset($publishNamespace->editJobId)) {
			$jobData = $this->_model->fetchJobById($publishNamespace->editJobId);
			$title->setValue($jobData['Title']);
			$company->setValue($jobData['Company']);
			$location->setValue($jobData['Location']);
			$category->setValue($jobData['CategoryID']);
			$description->setValue($jobData['Description']);
			$application->setValue($jobData['ToApply']);
			$submit->setLabel('Modify');
		}
			
		$form->addElement($title)
			 ->addElement($company)
			 ->addElement($location)
			 ->addElement($category)
			 ->addElement($description)
			 ->addElement($application)
			 ->addElement($submit);
			 
		$this->form = $form;
		
		// </createForm>
		
		// Render the form
		$this->view->form = $form->render;
		
		if ($this->getRequest()->isPost()) {
            $this->validateForm();
			return;
        }
		
		$this->view->form = $this->form->render();	
    }
	
	private function validateForm() {
		$form = $this->form;
		$publishNamespace = new Zend_Session_Namespace('PublishJob');
		
        if ($form->isValid($_POST)) {
			$jobOperations = new Joobsbox_Model_JobOperations;
			$values = $form->getValues();
			$hash = md5(implode("", $values));
			
			if(isset($publishNamespace->jobHash) && $publishNamespace->jobHash == $hash) {
				throw new Exception($this->view->translate("You are not allowed to add the same job multiple times."));
			}
			
			if(isset($publishNamespace->editJobId)) {
				// We have to modify it, nothing more to discuss
				try {
					$where = $jobOperations->getAdapter()->quoteInto('ID = ?', $publishNamespace->editJobId);
					$values['id'] = $jobOperations->update(array(
						'CategoryID'	=> $values['category'],
						'Title'			=> $values['title'],
						'Description'	=> $values['description'],
						'ToApply'		=> $values['application'],
						'Company'		=> $values['company'],
						'Location'		=> $values['location'],
						'ChangedDate'	=> new Zend_Db_Expr('NOW()'),
						'Public'		=> 1
					), $where);

					$this->view->editSuccess = 1;
					unset($publishNamespace->editJobId);
					$this->_helper->event("job_edited", $values);
					$publishNamespace->jobHash = $hash;
				} catch (Exception $e) {
					throw new Exception($this->view->translate("An error occured while saving the job. Please try again."));
				}
			} else {
				// Ok, here we go: insert the job into the database --- bombs away!
				try {
					$values['id'] = $jobOperations->insert(array(
						'CategoryID'	=> $values['category'],
						'Title'			=> $values['title'],
						'Description'	=> $values['description'],
						'ToApply'		=> $values['application'],
						'Company'		=> $values['company'],
						'Location'		=> $values['location'],
						'PostedAt'		=> new Zend_Db_Expr('NOW()'),
						'Public'		=> 0
					));

					$this->view->addSuccess = 1;
					$this->_helper->event("job_posted", $values);
					$publishNamespace->jobHash = $hash;
				} catch (Exception $e) {
					throw new Exception($this->view->translate("An error occured while saving the job. Please try again."));
				}
			}
		} else {
			$values = $form->getValues();
			$messages = $form->getMessages();
			$form->populate($values);
			$this->view->form = $form;
		}
		
	}
}