<?php

class FrontendObjectTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected static $fixture_file = 'FrontendObjectTest.yml';

    protected $extraDataObjects = array(
        'FrontendObjectTestPage',
    );

    public function setUp() {
        parent::setUp();

        // Config
        Config::inst()->remove('ObjectCreatorPage', 'createable_types');
        Config::inst()->update('ObjectCreatorPage', 'createable_types', array(
            'FrontendObjectTestPage',
        ));
    }

    /**
     * Test a user authoring a page and getting it reviewed/edited/published by another user
     */
    public function testPageAuthorAndApprover() {
        if (!class_exists('WorkflowDefinition')) {
            return;
        }

        $workflowDef = $this->importWorkflowDefinition('testPageAuthorAndApprover.yml');

        // 
        $page = $this->createWorkflowObjectCreatorPage();
        $page->WorkflowDefinitionID = $workflowDef->ID;

        $page->CanViewType = 'OnlyTheseUsers';
        $page->ViewerGroups()->add($this->objFromFixture('Group', 'creator'));
        $page->ViewerGroups()->add($this->objFromFixture('Group', 'approver'));
        
        $page->write();
        $page->publish('Stage', 'Live');

        // Ensure a non-logged in user cannot see the form
        $this->logInAs(0);
        $this->get('/'.$page->URLSegment);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));

        // Ensure a creator can create objects on the form
        $this->logInAs('creator');
        $this->get('/'.$page->URLSegment);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#Form_CreateForm')));

        // Submit and test that the page was created
        $this->submitForm('Form_CreateForm', 'action_createobject', array(
            'Title' => 'My new page',
            'Content' => '<p>The content on my page</p>',
        ));
        $createdPage = $this->getDataObjectLastCreated();
        $this->assertNotNull($createdPage);
        $this->assertEquals('My new page', $createdPage->Title);
        $this->assertEquals('<p>The content on my page</p>', $createdPage->Content);

        // Constants
        $EDIT_PAGE_URL = '/'.$page->URLSegment.'/edit/'.$createdPage->ID;
        $REVIEW_PAGE_URL = '/'.$page->URLSegment.'/review/'.$createdPage->ID;

        // Test to ensure 'creator' user can no longer edit the page
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));

        // 
        $this->logInAs('approver');

        // Open review page and click 'edit' button
        $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));
        $this->submitForm('FrontendWorkflowForm_Form2', 'action_doEdit', array(
            'ID' => $createdPage->ID,
        ));

        // Open edit page via URL (not action, this should work without needing to click the edit button)
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->submitForm('Form_CreateForm', 'action_editobject', array(
            'Title' => 'My new page updated title',
            'Content' => '<p>The updated content on my page</p>',
        ));
        // Reload the updated values from the DB
        $createdPage = Page::get()->byID($createdPage->ID);
        $this->assertNotNull($createdPage);
        $this->assertEquals('My new page updated title', $createdPage->Title);
        $this->assertEquals('<p>The updated content on my page</p>', $createdPage->Content);

        // Ensure saving/updating does not give 'creator' access again / break workflow
        $this->logInAs('creator');
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));

        //
        $this->logInAs('approver');
        $response = $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));

        $actionName = $this->getActionNameFromHTML('Approve');
        $this->assertNotEquals('', $actionName);

        $this->submitForm('FrontendWorkflowForm_Form2', $actionName, array());
        // Ensure an error page wasn't hit and that the forms have been hidden.
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));
        // TODO(Jake): Add default "review item listing" functionality and check for it.

        // Once workflow is finished and page is published, the approver should not be able 
        // edit or review. (ie. form should not exist)
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));

        // After workflow has finished, ensure original 'creator' cannot edit or review
        $this->logInAs('creator');
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));

        // Non-logged in user can see published page
        $this->logInAs(0);
        $response = $this->get($page->URLSegment);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Roles:
     *     - Media creator who creates an object, which enters a 'Preview' state which they 
     *       then edit further or review/preview. 
     *       Then submit to be reviewed by "Media Reviewer" (review-only) or 
     *       "Media Approver" ("review-edit and approve")
     *
     *     - Media reviewer who can review/edit 'media creator' objects and submit for further 
     *       approval from "media approver"
     *
     *     - Media Approver, who can review/edit/publish, this is the final stage.
     */
    public function testMediaCreatorReviewerApprover() {
        if (!class_exists('WorkflowDefinition')) {
            return;
        }
        $workflowDef = $this->importWorkflowDefinition('testMediaCreatorReviewerApprover.yml');

        // 
        $page = $this->createWorkflowObjectCreatorPage();
        $page->WorkflowDefinitionID = $workflowDef->ID;

        $page->CanViewType = 'OnlyTheseUsers';
        $page->ViewerGroups()->add($this->objFromFixture('Group', 'media-creator'));
        $page->ViewerGroups()->add($this->objFromFixture('Group', 'media-reviewer'));
        $page->ViewerGroups()->add($this->objFromFixture('Group', 'media-approver'));

        $page->write();
        $page->publish('Stage', 'Live');

        // Ensure a non-logged in user cannot see the form
        $this->logInAs(0);
        $this->get('/'.$page->URLSegment);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));

        // Can 'media-creator' see create form?
        $this->logInAs('media-creator');
        $this->get('/'.$page->URLSegment);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#Form_CreateForm')));

        // Submit and test that the page was created
        $response = $this->submitForm('Form_CreateForm', 'action_createobject', array(
            'Title' => 'My new media page',
            'Content' => '<p>The media content on my page</p>',
        ));
        $createdPage = $this->getDataObjectLastCreated();
        $this->assertNotNull($createdPage);
        $this->assertEquals('My new media page', $createdPage->Title);
        $this->assertEquals('<p>The media content on my page</p>', $createdPage->Content);

        // Constants
        $EDIT_PAGE_URL = '/'.$page->URLSegment.'/edit/'.$createdPage->ID;
        $REVIEW_PAGE_URL = '/'.$page->URLSegment.'/review/'.$createdPage->ID;

        // 'media-reviewer' and 'media-approver' cannot edit/review the page until its out of the 'Preview' step
        $this->logInAs('media-reviewer');
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));

        $this->logInAs('media-approver');
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));

        // 'media-creator' can *still* edit the page in the Preview state
        $this->logInAs('media-creator');
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#Form_CreateForm')));

        // 'media-creator' can review the page in the Preview state
        $response = $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));

        $actionName = $this->getActionNameFromHTML('Submit for approval');
        $this->assertNotEquals('', $actionName);
        $response = $this->submitForm('FrontendWorkflowForm_Form2', $actionName, array());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));

        // 'media-creator' can no longer edit or review this page.
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));

        // 'media-reviewer' and 'media-approver' can edit and review *after* Preview step
        $this->logInAs('media-approver');
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));
        $this->assertNotEquals('', $this->getActionNameFromHTML('Approve and Publish'));
        $this->logInAs('media-reviewer');
        $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));
        $this->assertNotEquals('', $this->getActionNameFromHTML('Approve and Send to Youth Approvers'));
        // A 'media-reviewer' cannot publish, that is the role of 'media-approver'
        $this->assertEquals('', $this->getActionNameFromHTML('Approve and Publish'));

        // 'media-reviewer' can edit and send to 'media-approver'
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->submitForm('Form_CreateForm', 'action_editobject', array(
            'Title' => 'My new page updated title',
            'Content' => '<p>The updated content on my page</p>',
        ));
        $createdPage = Page::get()->byID($createdPage->ID);
        $this->assertNotNull($createdPage);
        $this->assertEquals('My new page updated title', $createdPage->Title);
        $this->assertEquals('<p>The updated content on my page</p>', $createdPage->Content);

        $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));
        $actionName = $this->getActionNameFromHTML('Approve and Send to Youth Approvers');
        $this->assertNotEquals('', $actionName);
        // 'media-reviewer' cannot publish, that is the role of 'media-approver'
        $this->assertEquals('', $this->getActionNameFromHTML('Approve and Publish'));
        $this->submitForm('FrontendWorkflowForm_Form2', $actionName, array());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));
        // 'media-reviewer' can no longer edit or review
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));

        // 'media-approver' can edit and publish
        $this->logInAs('media-approver');
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->submitForm('Form_CreateForm', 'action_editobject', array(
            'Title' => 'My new approved page updated title',
            'Content' => '<p>The approved content on my page</p>',
        ));
        $createdPage = Page::get()->byID($createdPage->ID);
        $this->assertNotNull($createdPage);
        $this->assertEquals('My new approved page updated title', $createdPage->Title);
        $this->assertEquals('<p>The approved content on my page</p>', $createdPage->Content);
        $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(1, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));
        $actionName = $this->getActionNameFromHTML('Approve and Publish');
        $this->assertNotEquals('', $actionName);
        $this->submitForm('FrontendWorkflowForm_Form2', $actionName, array());

        // After workflow has finished, ensure original 'creator' cannot edit or review
        $this->logInAs('media-creator');
        $this->get($EDIT_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#Form_CreateForm')));
        $this->get($REVIEW_PAGE_URL);
        $this->assertEquals(0, count($this->cssParser()->getBySelector('#FrontendWorkflowForm_Form2')));

        // Non-logged in user can see published page
        $this->logInAs(0);
        $response = $this->get($page->URLSegment);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /** 
     * Get the <input name> attribute from the 'value'.
     *
     * @return string
     */
    private function getActionNameFromHTML($value) {
        $actionName = ''; // ie.  action_transition_3
        $actionButtons = $this->cssParser()->getBySelector('.action');
        foreach ($actionButtons as $actionButton) {
            $attributes = $actionButton->attributes();
            $v = $attributes['value']->__toString();
            if ($v === $value) {
                return $attributes['name'];
                break;
            }
        }
        return '';
    }

    /**
     * @return WorkflowDefinition
     */
    private function importWorkflowDefinition($name) {
        $workflowImporter = singleton('WorkflowDefinitionImporter');
        // NOTE: Requires Advaned Workflow 3.8+ 
        $this->assertTrue(method_exists($workflowImporter, 'importWorkflowDefinitionFromYAML'));
        // Import Workflow
        $workflowDef = $workflowImporter->importWorkflowDefinitionFromYAML(dirname(__FILE__).'/'.$name);
        $this->assertTrue($workflowDef && $workflowDef->exists());
        $workflowDef = WorkflowDefinition::get()->byID($workflowDef->ID);
        return $workflowDef;
    }

    private function getDataObjectLastCreated() {
        $pageType = ObjectCreatorPage::config()->createable_types[0];
        return $pageType::get()->sort('ID')->last();
    }

    /**
     * @return ObjectCreatorPage
     */
    private function createWorkflowObjectCreatorPage() {
        $page = ObjectCreatorPage::create();
        $page->Title = 'Test Creation Page';
        $page->URLSegment = 'create-page-workflow';
        $page->CreateType = ObjectCreatorPage::config()->createable_types[0];
        $page->CreateLocationID = $page->ID;
        $page->PublishOnCreate = false;
        $page->ReviewWithPageTemplate = true;
        $page->AllowEditing = true;
        $page->SuccessMessage = '<p class="frontend-objects-created">Page created successfully.</p>';
        $page->EditingSuccessMessage = '<p class="frontend-objects-edited">Page edited successfully.</p>';
        return $page;
    }
}