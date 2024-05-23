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
import('lib.pkp.classes.mail.MailTemplate');

class ConfirmMembershipTask extends ScheduledTask {
    
    public function executeActions() {
        $userDao = DAORegistry::getDAO('UserDAO'); /* @var $userDao UserDAO */
        $journalDao = DAORegistry::getDAO('JournalDAO'); /* @var $journalDao JournalDAO */
        $pluginSettings = new ConfirmMembershipPlugin();
        $this->sendConfirmMailAndDisabled($userDao, $journalDao, $pluginSettings);
    }

    // Send confirm membership email to users or delete them if they have not logged in.
    private function sendConfirmMailAndDisabled($userDao, $journalDao, $pluginSettings) {
        $daysSendMail = $pluginSettings->getSetting(CONTEXT_SITE, 'daysmail');
        $daysmerged = $pluginSettings->getSetting(CONTEXT_SITE, 'daysmerged');
        $maxusers = $pluginSettings->getSetting(CONTEXT_SITE, 'maxusers');
        $roleIds = explode(',', $pluginSettings->getSetting(CONTEXT_SITE, 'roleids'));
        $mergesUserId = $userDao->getByUsername($pluginSettings->getSetting(CONTEXT_SITE, 'mergeusername'))->getId();
        $timestamp = new DateTime(Core::getCurrentDate());
        $timestamp->modify('-' . $daysmerged . ' day');
        $paras = [Core::getCurrentDate(), $mergesUserId, $maxusers];
        $result =  $userDao->retrieve("select user_id from users  WHERE date_last_login < DATE(?) - interval ' $daysSendMail days' and disabled = 0 
       and user_id != ? order by RANDOM() LIMIT ? ",
        $paras);

        foreach ($result as $userId) {
            $user = $userDao->getById($userId->user_id);
            // It is not time to merge the user.
            if ($user->getData(SETTING_MEMBERSHIP_MAIL_SEND) && $timestamp < new DateTime($user->getData(SETTING_MEMBERSHIP_MAIL_SEND)) || $user->getData(SETTING_CAN_NOT_DELETE)) {
                continue;
            } else if ($user->getData(SETTING_MEMBERSHIP_MAIL_SEND) && $timestamp > new DateTime($user->getData(SETTING_MEMBERSHIP_MAIL_SEND))) {
                $this->mergeUsers($userDao, $journalDao, $roleIds, $user, $mergesUserId);
                continue;
            }

            $journals = $journalDao->getAll();
            // Find the name(s) of the journals the user is signed up for and check roles and subscriptions
            $memberJournals = [];
            while ($journal = $journals->next()) {
                foreach ($user->getRoles($journal->getId()) as $role) {
                    $memberJournals[] = $journal->getName($journal->getPrimaryLocale());
                    break;
                }
            }
            $journalsNames = '';
            if (!empty($memberJournals)) {
                $journalsNames = implode(', ', $memberJournals);
                $mail = new MailTemplate('COMFIRMMEMBERSHIP_MEMBERSHIP', 'en_US');
            }
            else {
                $mail = new MailTemplate('COMFIRMMEMBERSHIP_NO_JOURNALS_MEMBERSHIP', 'en_US');
            }
            if ($pluginSettings->getSetting(CONTEXT_SITE, 'test')) {
                $testmails = explode(';', $pluginSettings->getSetting(CONTEXT_SITE, 'testemails'));
                foreach ($testmails as $testmail) {
                    $mail->addRecipient($testmail);
                }
            } else {
                $mail->addRecipient($user->getEmail(), $user->getFullName());
            }
            $mail->assignParams([
                'fullname' => $user->getFullName(),
                'journal' => $journalsNames,
            ]);
           if ($mail->send()) {
                $user->updateSetting(SETTING_MEMBERSHIP_MAIL_SEND, Core::getCurrentDate(), 'Date', 0);
           }
           else{
               error_log('Error sending mail to user[' . $user->getId() . ']');
           }
        }
       $this->resetUsersSettins($userDao);
    }

    private function resetUsersSettins($userDao){
        $users=  $userDao->retrieve("select users.user_id from users, user_settings where date_last_login > NOW() - INTERVAL '356 days' and user_settings.setting_name = ? AND users.user_id = user_settings.user_id", [SETTING_MEMBERSHIP_MAIL_SEND]);
        $userSettingsDao = DAORegistry::getDAO('UserSettingsDAO'); /* @var $userDao UserDAO */
        foreach ($users as $user) {
            $userSettingsDao->deleteSetting($user->user_id, SETTING_MEMBERSHIP_MAIL_SEND);
            $userSettingsDao->deleteSetting($user->user_id, SETTING_CAN_NOT_DELETE);
        }
    }
    private function mergeUsers($userDao, $journalDao, $roleIds, $user, $mergesUserId) {
        $subscriptionDao = DAORegistry::getDAO('IndividualSubscriptionDAO');
        $instituSubscriptionDao = DAORegistry::getDAO('InstitutionalSubscriptionDAO');
        if ($this->userHasReviews($user->getId()) || $this->userHasSubmission($user->getId())) {
            $this->userCantBeDeleted($user, $userDao);
            return;
        }
        $journals = $journalDao->getAll();
        // Find the name(s) of the journals the user is signed up for and check roles and subscriptions
        while ($journal = $journals->next()) {
            if ($subscriptionDao->subscriptionExistsByUserForJournal($user->getId(), $journal->getId()) || $instituSubscriptionDao->subscriptionExistsByUserForJournal($user->getId(), $journal->getId())) {
                $this->userCantBeDeleted($user, $userDao);
                return;
            }
            foreach ($user->getRoles($journal->getId()) as $role) {
                if (!in_array($role->getId(), $roleIds)) {
                    $this->userCantBeDeleted($user, $userDao);
                    return;
                }
            }
        }
        $this->userAction = new UserAction();
        $userAction = $this->userAction;
        $userAction->mergeUsers($user->getId(), $mergesUserId);
    }
    private function userCantBeDeleted (&$user, $userDao) {
        $user->updateSetting(SETTING_CAN_NOT_DELETE, true, 'bool', 0);
        $userDao->updateObject($user);
        return true;
    }
    private function userHasReviews($userId) {
        $reviewersignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /** @var stageAssignmentDao StageAssignmentDAO */
        $checkUserSubmissions = $reviewersignmentDao->retrieve('SELECT count(*)  AS row_count  FROM review_assignments  where reviewer_id = ? ', [$userId]); //DB::table('stage_assignments')->where('user_id', $userId);
        $row = $checkUserSubmissions->current();
        return $row ? (boolean) $row->row_count : false;
    }
    private function userHasSubmission($userId) {
        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var stageAssignmentDao StageAssignmentDAO */
        $checkUserSubmissions = $stageAssignmentDao->retrieve("select count(*)  AS row_count from stage_assignments where user_id ?", [$userId]); //DB::table('stage_assignments')->where('user_id', $userId);
        $current = $checkUserSubmissions->current();
        $row = $current;
        return $row ? (boolean) $row->row_count : false;
    }
}