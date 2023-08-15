<?php
/**
 * @file classes/form/ConfirmMembershipPluginSettingsForm.inc.php
 * @class ConfirmMembershipPluginSettingsForm
 * @ingroup plugins_generic_confirmmembership_classes_form
 *
 * @brief Form to configure confirmmembership .
 */

import('lib.pkp.classes.form.Form');

class ConfirmMembershipPluginSettingsForm extends Form {

	public $plugin;

	public function __construct($plugin) {
		// Define the settings template and store a copy of the plugin object
		parent::__construct($plugin->getTemplateResource('settings.tpl'));
		$this->plugin = $plugin;
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * Load settings already saved in the database
	 *
	 * Settings are stored by context, so that each journal or press
	 * can have different settings.
	 */
	public function initData() {

		$this->setData('roleids', $this->plugin->getSetting(CONTEXT_SITE, 'roleids'));
        $this->setData('maxusers', $this->plugin->getSetting(CONTEXT_SITE, 'maxusers'));
        $this->setData('mergeusername', $this->plugin->getSetting(CONTEXT_SITE, 'mergeusername'));
        $this->setData('daysmail', $this->plugin->getSetting(CONTEXT_SITE, 'daysmail'));
        $this->setData('daysdisabled', $this->plugin->getSetting(CONTEXT_SITE, 'daysdisabled'));
        $this->setData('daysmerged', $this->plugin->getSetting(CONTEXT_SITE, 'daysmerged'));
        $this->setData('test', $this->plugin->getSetting(CONTEXT_SITE, 'test'));
        $this->setData('testemails', $this->plugin->getSetting(CONTEXT_SITE, 'testemails'));
		parent::initData();
	}

	/**
	 * Load data that was submitted with the form
	 */
	public function readInputData() {
		$this->readUserVars(['roleids']);
        $this->readUserVars(['maxusers']);
        $this->readUserVars(['mergeusername']);
        $this->readUserVars(['daysmail']);
        $this->readUserVars(['daysdisabled']);
        $this->readUserVars(['daysmerged']);
        $this->readUserVars(['test']);
        $this->readUserVars(['testemails']);
		parent::readInputData();
	}

	/**
	 * Fetch any additional data needed for your form.
	 *
	 * Data assigned to the form using $this->setData() during the
	 * initData() or readInputData() methods will be passed to the
	 * template.
	 */
	public function fetch($request, $template = null, $display = false) {

		// Pass the plugin name to the template so that it can be
		// used in the URL that the form is submitted to
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->plugin->getName());

		return parent::fetch($request, $template, $display);
	}

	/**
	 * Save the settings
	 */
	public function execute(...$functionArgs) {
		$this->plugin->updateSetting(CONTEXT_SITE, 'roleids', $this->getData('roleids'));
        $this->plugin->updateSetting(CONTEXT_SITE, 'maxusers', $this->getData('maxusers'));
        $this->plugin->updateSetting(CONTEXT_SITE, 'mergeusername', $this->getData('mergeusername'));
        $this->plugin->updateSetting(CONTEXT_SITE, 'daysmail', $this->getData('daysmail'));
        $this->plugin->updateSetting(CONTEXT_SITE, 'daysdisabled', $this->getData('daysdisabled'));
        $this->plugin->updateSetting(CONTEXT_SITE, 'daysmerged', $this->getData('daysmerged'));
        $this->plugin->updateSetting(CONTEXT_SITE, 'test', $this->getData('test'));
        $this->plugin->updateSetting(CONTEXT_SITE, 'testemails', $this->getData('testemails'));
		// Tell the user that the save was successful.
		import('classes.notification.NotificationManager');
		$notificationMgr = new NotificationManager();
		$notificationMgr->createTrivialNotification(
			Application::get()->getRequest()->getUser()->getId(),
			NOTIFICATION_TYPE_SUCCESS,
			['contents' => __('common.changesSaved')]
		);

		return parent::execute();
	}
}
