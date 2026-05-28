<?php
/**
 * @file classes/form/ConfirmMembershipPluginSettingsForm.php
 * @class ConfirmMembershipPluginSettingsForm
 * @ingroup plugins_generic_confirmmembership_classes_form
 *
 * @brief Form to configure confirmmembership.
 */

namespace APP\plugins\generic\confirmMembership\classes\form;

use PKP\form\Form;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorCSRF;
use APP\template\TemplateManager;
use APP\core\Application;
use PKP\notification\Notification;
use APP\notification\NotificationManager;
use Illuminate\Support\Facades\DB;

define('SETTING_CAN_NOT_DELETE', 'canNotDelete');

class ConfirmMembershipPluginSettingsForm extends Form {

    public $plugin;

    public function __construct($plugin) {
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
        $contextId = CONTEXT_SITE;
        $this->setData('roleids', $this->plugin->getSetting($contextId, 'roleids'));
        $this->setData('maxusers', $this->plugin->getSetting($contextId, 'maxusers'));
        $this->setData('mergeusername', $this->plugin->getSetting($contextId, 'mergeusername'));
        $this->setData('daysmail', $this->plugin->getSetting($contextId, 'daysmail'));
        $this->setData('daysmerged', $this->plugin->getSetting($contextId, 'daysmerged'));
        $this->setData('jobsautodelete', $this->plugin->getSetting($contextId, 'jobsautodelete'));
        $this->setData('test', $this->plugin->getSetting($contextId, 'test'));
        $this->setData('testemails', $this->plugin->getSetting($contextId, 'testemails'));
        $this->setData('amountofusers', $this->plugin->getSetting($contextId, 'amountofusers'));
        parent::initData();
    }

    /**
     * Load data that was submitted with the form
     */
    public function readInputData() {
        $this->readUserVars(['roleids', 'maxusers', 'mergeusername', 'daysmail', 'daysmerged', 'jobsautodelete', 'test', 'testemails', 'amountofusers']);
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
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        return parent::fetch($request, $template, $display);
    }

    /**
     * Save the settings
     */
    public function execute(...$functionArgs) {
        $request = Application::get()->getRequest();
        $values = $request->getUserVars();
        $contextId = CONTEXT_SITE;

        if (isset($values['delecteUserSetting'])) {
            DB::table('user_settings')
                ->where('setting_name', SETTING_CAN_NOT_DELETE)
                ->delete();
        } else {
            $this->plugin->updateSetting($contextId, 'roleids', $this->getData('roleids'));
            $this->plugin->updateSetting($contextId, 'maxusers', $this->getData('maxusers'));
            $this->plugin->updateSetting($contextId, 'mergeusername', $this->getData('mergeusername'));
            $this->plugin->updateSetting($contextId, 'daysmail', $this->getData('daysmail'));
            $this->plugin->updateSetting($contextId, 'daysmerged', $this->getData('daysmerged'));
            $this->plugin->updateSetting($contextId, 'jobsautodelete', $this->getData('jobsautodelete'));
            $this->plugin->updateSetting($contextId, 'test', $this->getData('test'));
            $this->plugin->updateSetting($contextId, 'testemails', $this->getData('testemails'));
            $this->plugin->updateSetting($contextId, 'amountofusers', $this->getData('amountofusers'));

            $notificationMgr = new NotificationManager();
            $notificationMgr->createTrivialNotification(
                $request->getUser()->getId(),
                Notification::NOTIFICATION_TYPE_SUCCESS,
                ['contents' => __('common.changesSaved')]
            );
        }
        return parent::execute(...$functionArgs);
    }
}
