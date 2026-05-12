<?php

/**
 * @file plugins/generic/confirmMembership/ConfirmMembershipPlugin.php
 *
 *
 * @class ConfirmMembershipPlugin
 * @ingroup plugins_generic_confirmmembership
 *
 * @brief confirmmembership plugin class
 */

namespace APP\plugins\generic\confirmMembership;

use PKP\plugins\GenericPlugin;
use PKP\config\Config;
use PKP\plugins\Hook;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use APP\core\Application;
use PKP\db\DAORegistry;
use APP\plugins\generic\confirmMembership\classes\form\ConfirmMembershipPluginSettingsForm;

define("SETTING_CAN_NOT_DELETE", "membershipcannotdelete");
define('SETTING_MEMBERSHIP_MAIL_SEND', 'confirmmembershipmailsend');

class ConfirmMembershipPlugin extends GenericPlugin {
    public $injected = false;

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null) {
        $success = parent::register($category, $path, $mainContextId);
        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;
        if ($success && $this->getEnabled()) {
            Hook::add('AcronPlugin::parseCronTab', [$this, 'callbackParseCronTab']);
            Hook::add('LoadHandler', [$this, 'setPageHandler']);
        }
        return $success;
    }

    public function setEnabled($enabled) {
        if ($enabled) {
            $emailTemplateDao = DAORegistry::getDAO('EmailTemplateDAO');
            $emailTemplateDao->installEmailTemplates($this->getInstallEmailTemplatesFile(), ['en'], false, 'COMFIRMMEMBERSHIP_MEMBERSHIP');
            $emailTemplateDao->installEmailTemplates($this->getInstallEmailTemplatesFile(), ['en'], false, 'COMFIRMMEMBERSHIP_NO_JOURNALS_MEMBERSHIP');
        }
        parent::setEnabled($enabled);
    }

    public function getInstallSitePluginSettingsFile() {
        return $this->getPluginPath() . '/settings.xml';
    }

    public function getInstallEmailTemplatesFile() {
        return ($this->getPluginPath() . DIRECTORY_SEPARATOR . 'emailTemplates.xml');
    }

    /**
     * @see AcronPlugin::parseCronTab()
     * @param string $hookName
     * @param array $args
     * @return bool
     */
    public function callbackParseCronTab($hookName, $args) {
        if ($this->getEnabled() || !Config::getVar('general', 'installed')) {
            $taskFilesPath =& $args[0];
            $taskFilesPath[] = $this->getPluginPath() . DIRECTORY_SEPARATOR . 'scheduledTasks.xml';
        }
        return false;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName() {
        return __('plugins.generic.confirmmembership.display.name');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription() {
        return __('plugins.generic.confirmmembership.description');
    }

    public function getContextSpecificPluginSettingsFile() {
        return $this->getPluginPath() . '/settings.xml';
    }

    public function getCanEnable() {
        return true;
    }

    /**
     * @see Plugin::isSitePlugin()
     */
    public function isSitePlugin() {
        return true;
    }

    public function getCanDisable() {
        $request = Application::get()->getRequest();
        $user = $request->getUser();
        return $user && $user->hasRole([ROLE_ID_SITE_ADMIN]);
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs) {
        // Get the existing actions
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        // Create a LinkAction that will call the plugin's
        // `manage` method with the `settings` verb.
        $router = $request->getRouter();
        $linkAction = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    [
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic'
                    ]
                ),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        );
        array_unshift($actions, $linkAction);
        return $actions;
    }

    public function manage($args, $request) {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $form = new ConfirmMembershipPluginSettingsForm($this);
                if (!$request->getUserVar('save')) {
                    $form->initData();
                    return new JSONMessage(true, $form->fetch($request));
                }
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    return new JSONMessage(true);
                }
        }
        return parent::manage($args, $request);
    }

    public function setPageHandler($hookName, $params) {
        if ($params[0] === 'deleteusers') {
            require_once($this->getPluginPath() . '/ConfirmMembershipPluginHandler.php');
            define('HANDLER_CLASS', '\APP\plugins\generic\confirmMembership\ConfirmMembershipPluginHandler');
            return true;
        }
        return false;
    }
}
