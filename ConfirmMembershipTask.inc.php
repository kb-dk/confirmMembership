<?php
/**
 * @file plugins/generic/confirmMember/RDLSendConfirmMembershipMailTask
 * k.inc.php
 *
 *
 * @class ConfirmMembershipTask
 * @ingroup tasks
 *
 * @brief Send mail to users to ask them to login for confirming membership
 */

/**
 * @copydoc ScheduledTask::executeActions()
 */
import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('classes.user.UserAction');
import('plugins.generic.RDLUser.RDLUserPlugin');
define("SETTING_CAN_NOT_DELETE", "membershipcannotdelete");
define("SETTING_MEMBERSHIP_MAIL_SEND", "confirmmembershipmailsend");
define("CONFIRM_MEMBERSHIP_DISABLED_REASON", 'Membership not yet confirmed');
class ConfirmMembershipTask extends ScheduledTask {
    
    public function executeActions() {
        $userDao = DAORegistry::getDAO('UserDAO'); /* @var $userDao UserDAO */
        $journalDao = DAORegistry::getDAO('JournalDAO'); /* @var $journalDao JournalDAO */
        $pluginSettings = new ConfirmMembershipPlugin();
        $this->sendConfirmMailAndDisabled($userDao, $journalDao, $pluginSettings);
        $this->mergeUsersNotConfirmed($userDao, $journalDao, $pluginSettings);
    }
    // Find users who have received the confirmation email and have been disabled and now need to be deleted
    private function mergeUsersNotConfirmed($userDao, $journalDao, $pluginSettings) {
        $roleIds = $pluginSettings->getSetting(CONTEXT_SITE, 'roleids');
        $daysToMerged = $pluginSettings->getSetting(CONTEXT_SITE, 'daysmerged');
        dump($daysToMerged);
        $mergesUserId = $userDao->getByUsername($pluginSettings->getSetting(CONTEXT_SITE, 'mergeusername'))->getId();
        $roleIds = explode(',', $roleIds);
        $paras = [CONFIRM_MEMBERSHIP_DISABLED_REASON, SETTING_MEMBERSHIP_MAIL_SEND, Core::getCurrentDate()];
        $usersToDelete = $userDao->retrieve("select users.user_id  from users, user_settings where users.user_id = user_settings.user_id and disabled = 1 
        and disabled_reason = ? and user_settings.setting_name= ?
        and TO_TIMESTAMP(user_settings.setting_value, 'yyyy-MM-DD HH24:MI:SS') < TO_TIMESTAMP(?, 'yyyy-MM-DD HH24:MI:SS') - interval  ' $daysToMerged days'",
        $paras);
        // Check if users has a role we aren't allowed to delete automatic.
        dump($paras);
        foreach ($usersToDelete as $userId) {
            dump($userId);
            $user = $userDao->getById($userId->user_id);
            if ($this->userHasSubmission($user->getId())) {
                $this->userCantBeDeleted($user, $userDao);
                continue;
            }
            $journals = $journalDao->getAll();
            while ($journal = $journals->next()) {
                if ($user->getRoles($journal->getId())) {
                    foreach ($user->getRoles($journal->getId()) as $role) {
                        if (!in_array($role->getId(), $roleIds)) {
                            $this->userCantBeDeleted($user, $userDao);
                            break;
                        }
                    }
                }
            }
            if (!$user->getSetting(SETTING_CAN_NOT_DELETE)) {
                $this->userAction = new UserAction();
                $userAction = $this->userAction;
                $userAction->mergeUsers($user->getId(), $mergesUserId);
            }
        }
    }
    // Send confirm membership email to users or disabled them if they have not logged in.
    private function sendConfirmMailAndDisabled($userDao, $journalDao, $pluginSettings) {
        $daysSendMail = $pluginSettings->getSetting(CONTEXT_SITE, 'daysmail');
        $daysDisabled = $pluginSettings->getSetting(CONTEXT_SITE, 'daysdisabled');
        $maxusers = $pluginSettings->getSetting(CONTEXT_SITE, 'maxusers');
        $paras = [Core::getCurrentDate(), $maxusers];
        $result =  $userDao->retrieve("select user_id from users where date_last_login < DATE(?) - interval ' $daysSendMail days' and disabled = 0 
        order by date_last_login LIMIT ?",
        $paras);
        dump($result);
        $journals = $journalDao->getAll();
        foreach ($result as $userId) {
            dump($userId);
            $memberJournals = [];

            $user = $userDao->getById($userId->user_id);

            //dump($user);
            $timestamp = new DateTime(Core::getCurrentDate());
            dump('user_id:' . $user->getId());
            $timestamp->modify('-' . $daysDisabled . ' minutes');
            dump($timestamp);
            // It is not time to disable the user.
            if ($user->getData(SETTING_MEMBERSHIP_MAIL_SEND) && $timestamp < new DateTime($user->getData(SETTING_MEMBERSHIP_MAIL_SEND))) {
                dump('contine');
                continue;
            } else if ($user->getData(SETTING_MEMBERSHIP_MAIL_SEND) && $timestamp > new DateTime($user->getData(SETTING_MEMBERSHIP_MAIL_SEND))) {
                $user->setDisabled(true);
                $user->setDisabledReason(CONFIRM_MEMBERSHIP_DISABLED_REASON);
                dump('user disable:' . $user->getId());
                $userDao->updateObject($user);
                continue;
            }
            // Find the name(s) of the journals the user is signed op for'
            while ($journal = $journals->next()) {
                if ($user->getRoles($journal->getId())) {
                    $memberJournals[] = $journal->getName($journal->getPrimaryLocale());
                }
            }
            $siteDao = DAORegistry::getDAO('SiteDAO');
            $site = $siteDao->getSite();
            $journalsNames = $site->getData('title', 'en_US');
            if (!empty($memberJournals)) {
                $journalsNames = implode(', ', $memberJournals);
            }

            import('lib.pkp.classes.mail.MailTemplate');
            $mail = new MailTemplate('COMFIRMMEMBERSHIP_MEMBERSHIP', 'en_US');
            $mail->addRecipient($user->getEmail(), $user->getFullName());
            $mail->assignParams([
                'fullname' => $user->getFullName(),
                'site' => $journalsNames,
            ]);
            if ($mail->send()) {
                $user->updateSetting(SETTING_MEMBERSHIP_MAIL_SEND, Core::getCurrentDate(), 'Date', 0);
            }
        }
    }
    private function userCantBeDeleted ($user, $userDao) {
        $user->setDisabledReason('Disabled by Confirm Membership plugin - delete have to be done manually');
        $user->updateSetting(SETTING_CAN_NOT_DELETE, true, 'bool', 0);
        $userDao->updateObject($user);
    }
    private function userHasSubmission($userId) {
        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var stageAssignmentDao StageAssignmentDAO */
        $checkUserSubmissions = $stageAssignmentDao->retrieve("select count(*)  AS row_count from stage_assignments where user_id = $userId"); //DB::table('stage_assignments')->where('user_id', $userId);
        $current = $checkUserSubmissions->current();
        $row = $current;
        return $row ? (boolean) $row->row_count : false;
    }
}