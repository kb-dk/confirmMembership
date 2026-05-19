<?php

/**
 * @file plugins/generic/confirmMembership/ConfirmMembershipTask.php
 *
 *
 * @class ConfirmMembershipTask
 * @ingroup tasks
 *
 * @brief Send mail to users to ask them to login for confirming membership
 */

namespace APP\plugins\generic\confirmMembership;

use PKP\scheduledTask\ScheduledTask;
use PKP\db\DAORegistry;
use PKP\core\Core;
use PKP\core\PKPApplication;
use APP\facades\Repo;
use PKP\mail\MailService;
use PKP\plugins\PluginRegistry;
use DateTime;

class ConfirmMembershipTask extends ScheduledTask
{
    /** @var object Plugin instance */
    private $plugin;

    /**
     * Constructor
     */
    public function __construct($plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
    }

    /**
     * @copydoc ScheduledTask::executeActions()
     */
    public function executeActions(): bool
    {
        $this->sendConfirmMailAndDisabled($this->plugin);
        return true;
    }

    // Send confirm membership email to users or delete them if they have not logged in.
    private function sendConfirmMailAndDisabled($pluginSettings)
    {
        $subscriptionDao = DAORegistry::getDAO('IndividualSubscriptionDAO');
        $instituSubscriptionDao = DAORegistry::getDAO('InstitutionalSubscriptionDAO');
        $contextDao = \APP\core\Application::getContextDAO();

        $daysSendMail = $pluginSettings->getSetting(PKPApplication::CONTEXT_SITE, 'daysmail');
        $daysmerged = $pluginSettings->getSetting(PKPApplication::CONTEXT_SITE, 'daysmerged');
        $maxusers = $pluginSettings->getSetting(PKPApplication::CONTEXT_SITE, 'maxusers');
        $roleIds = explode(',', $pluginSettings->getSetting(PKPApplication::CONTEXT_SITE, 'roleids'));
        $mergeUsername = $pluginSettings->getSetting(PKPApplication::CONTEXT_SITE, 'mergeusername');
        $mergeUser = Repo::user()->getByUsername($mergeUsername);
        $mergesUserId = $mergeUser ? $mergeUser->getId() : null;

        if (!$mergesUserId) {
            error_log('Merge user not found: ' . $mergeUsername);
            return;
        }

        $jobsAutoDeleteAge = $pluginSettings->getSetting(PKPApplication::CONTEXT_SITE, 'jobsautodelete');
        $timestamp = new DateTime(Core::getCurrentDate());
        $timestamp->modify('-' . $daysmerged . ' day');

        $paras = [Core::getCurrentDate(), $mergesUserId, $maxusers];
        $result = \Illuminate\Support\Facades\DB::select("SELECT user_id FROM users
             WHERE date_last_login < DATE(?) - interval '$daysSendMail days'
             AND disabled = 0
             AND user_id != ?
             ORDER BY RANDOM()
             LIMIT ?",
            $paras
        );

        foreach ($result as $userIdObj) {
            $user = Repo::user()->get($userIdObj->user_id);
            if (!$user) continue;

            // It is not time to merge the user.
            if ($user->getData(SETTING_MEMBERSHIP_MAIL_SEND) &&
                $timestamp < new DateTime($user->getData(SETTING_MEMBERSHIP_MAIL_SEND)) ||
                $user->getData(SETTING_CAN_NOT_DELETE)) {
                continue;
            } else if ($user->getData(SETTING_MEMBERSHIP_MAIL_SEND) &&
                $timestamp > new DateTime($user->getData(SETTING_MEMBERSHIP_MAIL_SEND))) {
                $this->mergeUsers($subscriptionDao, $instituSubscriptionDao, $roleIds, $user, $mergesUserId, $jobsAutoDeleteAge, $contextDao);
                continue;
            }

            // Get all contexts (journals)
            $contexts = $contextDao->getAll(true);

            // Find the name(s) of the journals the user is signed up for and check roles and subscriptions
            $memberJournals = [];
            while ($journal = $contexts->next()) {
                $userGroups = Repo::userGroup()->userUserGroups($user->getId(), $journal->getId());
                if (!empty($userGroups)) {
                    // Get the journal name from the database directly
                    $journalName = $journal->getData('name');
                    if (is_array($journalName)) {
                        // Get the first available locale
                        $journalName = reset($journalName);
                    }
                    $memberJournals[] = $journalName;
                }
            }

            $journalsNames = '';
            $mailTemplateKey = '';

            if (!empty($memberJournals)) {
                $journalsNames = implode(', ', $memberJournals);
                $mailTemplateKey = 'COMFIRMMEMBERSHIP_MEMBERSHIP';
            } else {
                $mailTemplateKey = 'COMFIRMMEMBERSHIP_NO_JOURNALS_MEMBERSHIP';
            }

            // Get email template
            $emailTemplate = Repo::emailTemplate()->getByKey(PKPApplication::CONTEXT_SITE, $mailTemplateKey);

            if (!$emailTemplate) {
                error_log('Email template not found: ' . $mailTemplateKey);
                continue;
            }

            // Get template data directly without using getLocalizedData()
            $subject = $emailTemplate->getData('subject');
            $body = $emailTemplate->getData('body');

            // Handle localized data
            if (is_array($subject)) {
                $subject = reset($subject);
            }
            if (is_array($body)) {
                $body = reset($body);
            }

            // Replace template variables
            $subject = str_replace('{$fullname}', $user->getFullName(), $subject);
            $subject = str_replace('{$journal}', $journalsNames, $subject);

            $body = str_replace('{$fullname}', $user->getFullName(), $body);
            $body = str_replace('{$journal}', $journalsNames, $body);

            $mailable = new \PKP\mail\Mailable();
            $mailable->subject($subject)
                ->body($body);

            if ($pluginSettings->getSetting(PKPApplication::CONTEXT_SITE, 'test')) {
                $testmails = explode(';', $pluginSettings->getSetting(PKPApplication::CONTEXT_SITE, 'testemails'));
                foreach ($testmails as $testmail) {
                    $mailable->to($testmail);
                }
            } else {
                $mailable->to($user->getEmail(), $user->getFullName());
            }

            try {
                \Illuminate\Support\Facades\Mail::send($mailable);
                $user->setData(SETTING_MEMBERSHIP_MAIL_SEND, Core::getCurrentDate());
                Repo::user()->edit($user, []);
                echo "Email sent to: " . $user->getEmail() . "\n";
            } catch (\Exception $e) {
                error_log('Error sending mail to user[' . $user->getId() . ']: ' . $e->getMessage());
            }
        }

        $this->resetUsersSettings();
    }

    private function resetUsersSettings()
    {
        $users = \Illuminate\Support\Facades\DB::select(
            "SELECT users.user_id
             FROM users, user_settings
             WHERE date_last_login > NOW() - INTERVAL '365 days'
             AND user_settings.setting_name = ?
             AND users.user_id = user_settings.user_id",
            [SETTING_MEMBERSHIP_MAIL_SEND]
        );

        foreach ($users as $userObj) {
            $user = Repo::user()->get($userObj->user_id);
            if ($user) {
                $user->setData(SETTING_MEMBERSHIP_MAIL_SEND, null);
                $user->setData(SETTING_CAN_NOT_DELETE, null);
                Repo::user()->edit($user, []);
            }
        }
    }

    private function mergeUsers($subscriptionDao, $instituSubscriptionDao, $roleIds, $user, $mergesUserId, $jobsAutoDeleteAge, $contextDao)
    {
        if ($this->userHasReviews($user->getId(), $jobsAutoDeleteAge) ||
            $this->userHasSubmission($user->getId(), $jobsAutoDeleteAge)) {
            $this->userCantBeDeleted($user);
            return;
        }

        // Get all contexts (journals)
        $contexts = $contextDao->getAll(true);

        // Find the name(s) of the journals the user is signed up for and check roles and subscriptions
        while ($journal = $contexts->next()) {
            if ($subscriptionDao->subscriptionExistsByUserForJournal($user->getId(), $journal->getId()) ||
                $instituSubscriptionDao->subscriptionExistsByUserForJournal($user->getId(), $journal->getId())) {
                $this->userCantBeDeleted($user);
                return;
            }

            $userGroups = Repo::userGroup()->userUserGroups($user->getId(), $journal->getId());
            foreach ($userGroups as $userGroup) {
                if (!in_array($userGroup->getId(), $roleIds)) {
                    $this->userCantBeDeleted($user);
                    return;
                }
            }
        }

        Repo::user()->merge($user->getId(), $mergesUserId);
    }

    private function userCantBeDeleted($user)
    {
        $user->setData(SETTING_CAN_NOT_DELETE, true);
        Repo::user()->edit($user, []);
        return true;
    }

    private function userHasReviews($userId, $jobsAutoDeleteAge)
    {
        $result = \Illuminate\Support\Facades\DB::select(
            "SELECT count(*) AS row_count
             FROM review_assignments
             WHERE reviewer_id = ?
             AND last_modified > DATE(?) - interval '$jobsAutoDeleteAge days'",
            [$userId, Core::getCurrentDate()]
        );

        return $result && isset($result[0]) ? (bool) $result[0]->row_count : false;
    }

    private function userHasSubmission($userId, $jobsAutoDeleteAge)
    {
        $result = \Illuminate\Support\Facades\DB::select(
            "SELECT count(*) AS row_count
             FROM stage_assignments
             JOIN submissions ON stage_assignments.submission_id = submissions.submission_id
             WHERE last_modified > DATE(?) - interval '$jobsAutoDeleteAge days'
             AND user_id = ?",
            [Core::getCurrentDate(), $userId]
        );

        return $result && isset($result[0]) ? (bool) $result[0]->row_count : false;
    }
}
