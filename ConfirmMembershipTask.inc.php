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
define("SETTING_CAN_NOT_DELETE", "membershipcannotdelete");
define("SETTING_MEMBERSHIP_MAIL_SEND", "confirmmembershipmailsend");
define("CONFIRM_MEMBERSHIP_DISABLED_REASON", 'Membership not yet confirmed');
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
        $paras = [Core::getCurrentDate(), $mergesUserId,  $maxusers];
        $result =  $userDao->retrieve("select user_id from users user_settings WHERE date_last_login < DATE(?) - interval ' $daysSendMail days' and disabled = 0 
       and user_id != ? order by RANDOM() LIMIT ? ",
        $paras);
        $subscriptionDao = DAORegistry::getDAO('IndividualSubscriptionDAO');
        foreach ($result as $userId) {
            $memberJournals = [];
            $user = $userDao->getById($userId->user_id);
            // It is not time to merge the user.
            if ($user->getData(SETTING_MEMBERSHIP_MAIL_SEND) && $timestamp < new DateTime($user->getData(SETTING_MEMBERSHIP_MAIL_SEND)) || $user->getData(SETTING_CAN_NOT_DELETE)) {
                continue;
            } else if ($user->getData(SETTING_MEMBERSHIP_MAIL_SEND) && $timestamp > new DateTime($user->getData(SETTING_MEMBERSHIP_MAIL_SEND))) {
                $this->userAction = new UserAction();
                $userAction = $this->userAction;
                $userAction->mergeUsers($user->getId(), $mergesUserId);
                continue;
            }
            $userCantBeDeleted = false;
            if ($this->userHasSubmission($user->getId())) {
                $userCantBeDeleted = $this->userCantBeDeleted($user, $userDao);
                continue;
            }
            $journals = $journalDao->getAll();
            // Find the name(s) of the journals the user is signed up for and check roles and subscriptions
            while ($journal = $journals->next()) {
                if ($subscriptionDao->isValidIndividualSubscription($user->getId(), $journal->getId())) {
                    $userCantBeDeleted = $this->userCantBeDeleted($user, $userDao);
                    break;
                }
                foreach ($user->getRoles($journal->getId()) as $role) {
                    if (!in_array($role->getId(), $roleIds)) {
                        $userCantBeDeleted = $this->userCantBeDeleted($user, $userDao);
                        break;
                    }
                    $memberJournals[] = $journal->getName($journal->getPrimaryLocale());
                }
            }
            if (!$userCantBeDeleted) {
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
                    dump('mail send to ' . $user->getId());
                }
            }
        }
    }
    private function userCantBeDeleted (&$user, $userDao) {
        $user->updateSetting(SETTING_CAN_NOT_DELETE, true, 'bool', 0);
        $userDao->updateObject($user);
        return true;
    }
    private function userHasSubmission($userId) {
        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var stageAssignmentDao StageAssignmentDAO */
        $checkUserSubmissions = $stageAssignmentDao->retrieve("select count(*)  AS row_count from stage_assignments where user_id = $userId"); //DB::table('stage_assignments')->where('user_id', $userId);
        $current = $checkUserSubmissions->current();
        $row = $current;
        return $row ? (boolean) $row->row_count : false;
    }
}