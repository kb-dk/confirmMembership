<?php
/**
 * @file plugins/generic/confirmMembership/ConfirmMembershipPlugin.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @package plugins.generic.customBlockManager
 *
 * @class CustomBlockManagerPlugin
 *
 * Plugin to let managers add and delete custom sidebar blocks
 *
 */
namespace APP\plugins\generic\confirmMembership;

use Illuminate\Support\Facades\DB;
use PKP\locale\AppLocale;
use PKP\plugins\GenericPlugin;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\security\Validation;
use PKP\plugins\Hook;
use PKP\core\JSONMessage;
use PKP\config\Config;
use PKP\DAORegistry;

class ConfirmMembershipPlugin extends GenericPlugin {
    private bool $injected = false;
    public const SETTING_MEMBERSHIP_MAIL_SEND = 'confirmmembershipmailsend';
        public const SETTING_CAN_NOT_DELETE = "membershipcannotdelete";
    public function register( $category, $path, $mainContextId = null): bool {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled()) {
            Hook::add('AcronPlugin::parseCronTab', [$this, 'callbackParseCronTab']);
            Hook::add('LoadHandler', [$this, 'setPageHandler']);
        }

        return $success;
    }

    public function setEnabled($enabled): void {
        // Call parent method first if necessary
        parent::setEnabled($enabled);

        if ($enabled) {
            // Define the path for the email locale file
            $emailFile = $this->getPluginPath() . '/locale/en_US/emails.po';

            // Register the locale file
            AppLocale::registerLocaleFile('en_US', $emailFile);

            // Get the EmailTemplateDAO instance
            $emailTemplateDao = DAORegistry::getDAO('EmailTemplateDAO');

            // Install the email templates for membership confirmation
            $emailTemplateDao->installEmailTemplates($this->getInstallEmailTemplatesFile(), ['en_US'], false, 'COMFIRMMEMBERSHIP_MEMBERSHIP');
            $emailTemplateDao->installEmailTemplates($this->getInstallEmailTemplatesFile(), ['en_US'], false, 'COMFIRMMEMBERSHIP_NO_JOURNALS_MEMBERSHIP');
        }
    }
    public function getInstallSitePluginSettingsFile(): string {
        return $this->getPluginPath() . '/settings.xml';
    }

    public function getInstallEmailTemplatesFile(): string {
        return $this->getPluginPath() . DIRECTORY_SEPARATOR . 'emailTemplates.xml';
    }

    public function callbackParseCronTab( $hookName, array $args): bool {
        if ($this->getEnabled() || !Config::getVar('general', 'installed')) {
            $taskFilesPath =& $args[0];
            $taskFilesPath[] = $this->getPluginPath() . DIRECTORY_SEPARATOR . 'scheduledTasks.xml';
        }
        return false;
    }

    public function getDisplayName(): string {
        return __('plugins.generic.confirmmembership.display.name');
    }

    public function getDescription(): string {
        return __('plugins.generic.confirmmembership.description');
    }

    public function getContextSpecificPluginSettingsFile(): string {
        return $this->getPluginPath() . '/settings.xml';
    }

    public function getCanEnable(): bool {
        return true;
    }

    public function isSitePlugin(): bool {
        return true;
    }

    public function getCanDisable(): bool {
        return Validation::isSiteAdmin();
    }

    public function getActions($request, $actionArgs): array {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

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

                // Load the custom form
                $form = new ConfirmMembershipPluginSettingsForm($this);

                // Fetch the form the first time it loads, before
                // the user has tried to save it
                if (!$request->getUserVar('save')) {
                    $form->initData();
                    return new JSONMessage(true, $form->fetch($request));
                }

                // Validate and execute the form
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    return new JSONMessage(true);
                }
        }
        return parent::manage($args, $request);
    }


    public function setPageHandler($hookName, $params)
    {
        if ($params[0] === 'deleteusers') {
            // Import the handler class for your plugin
            import('plugins.generic.confirmMembership.ConfirmMembershipPluginHandler'); // Adjust the path as necessary

            // Define the handler class constant
            define('HANDLER_CLASS', 'APP\plugins\generic\confirmMembership\ConfirmMembershipPluginHandler');

            return true; // Indicates that the handler has been set
        }
        return false; // Indicates that the hook was not handled
    }


    static function getUserSettings($userId, $settingName)
    {
        $result = DB::select("select setting_value from user_settings where user_id = ? and setting_name = ?", [$userId, $settingName]);
        return isset($result[0]) ? $result[0]->setting_value : null;
    }
}

