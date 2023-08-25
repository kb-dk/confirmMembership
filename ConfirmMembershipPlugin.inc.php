<?php

/**
 * @file plugins/generic/confirmMembership/ConfirmMembershipPlugin.inc.php
 *
 *
 * @class ConfirmMembershipPlugin
 * @ingroup plugins_generic_confirmmembership
 *
 * @brief confirmmembership plugin class
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class ConfirmMembershipPlugin extends GenericPlugin {
    /**
     * @copydoc Plugin::register()
     */
    function register($category, $path, $mainContextId = null) {
        $success = parent::register($category, $path, $mainContextId);

        HookRegistry::register('AcronPlugin::parseCronTab', array($this, 'callbackParseCronTab'));
        return $success;
    }
    function setEnabled($enabled) {
        if ($enabled) {
            $emailFile = $this->getPluginPath() . "/locale/en_US/emails.po";
            AppLocale::registerLocaleFile('en_US', $emailFile);
            $emailTemplateDao = DAORegistry::getDAO('EmailTemplateDAO');
            /* @var $emailTemplateDao EmailTemplateDAO */
            $emailTemplateDao->installEmailTemplates($this->getInstallEmailTemplatesFile(), ['en_US'], false, 'COMFIRMMEMBERSHIP_MEMBERSHIP');
            $emailTemplateDao->installEmailTemplates($this->getInstallEmailTemplatesFile(), ['en_US'], false, 'COMFIRMMEMBERSHIP_NO_JOURNALS_MEMBERSHIP');
        }
      parent::setEnabled($enabled);

    }
    function getInstallSitePluginSettingsFile() {
        return $this->getPluginPath() . '/settings.xml';
    }
    function getInstallEmailTemplatesFile() {
        return ($this->getPluginPath() . DIRECTORY_SEPARATOR . 'emailTemplates.xml');
    }
    /**
     * @see AcronPlugin::parseCronTab()
     * @param $hookName string
     * @param $args array [
     *  @option array Task files paths
     * ]
     * @return bolean
     */
    function callbackParseCronTab($hookName, $args) {
        error_log('callbackParseCronTab 1');
        if ($this->getEnabled() || !Config::getVar('general', 'installed')) {
            error_log('callbackParseCronTab');
            $taskFilesPath =& $args[0];
            $taskFilesPath[] = $this->getPluginPath() . DIRECTORY_SEPARATOR . 'scheduledTasks.xml';
        }
        return false;
    }
    /**
     * @copydoc Plugin::getDisplayName()
     */
    function getDisplayName() {
        return __('plugins.generic.confirmmembership.display.name');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    function getDescription() {
        return __('plugins.generic.confirmmembership.description');
    }

    function getContextSpecificPluginSettingsFile() {
        return $this->getPluginPath() . '/settings.xml';
    }

    function getCanEnable()
    {
        return true;
    }


    /**
     * @see Plugin::isSitePlugin()
     */
    function isSitePlugin() {
        return true;
    }
    function getCanDisable() {
        if (Validation::isSiteAdmin()) {
            return true;
        }
        else {
            return false;
        }
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
        import('lib.pkp.classes.linkAction.request.AjaxModal');
        $linkAction = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    array(
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic'
                    )
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
                $this->import('classes.form.ConfirmMembershipPluginSettingsForm');
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
}

