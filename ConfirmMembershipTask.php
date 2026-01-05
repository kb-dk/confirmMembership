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
namespace APP\plugins\generic\confirmMembership;
/**
 * @copydoc ScheduledTask::executeActions()
 */

use APP\plugins\generic\confirmMembership\ConfirmMembershipPlugin;
use Illuminate\Support\Facades\Mail;
use PKP\core\PKPApplication;
use PKP\mail\Mailable;
use PKP\scheduledTask\ScheduledTask;
use PKP\user\Repository;
use PKP\config\Config;
use PKP\db\DAORegistry;
use APP\facades\Repo;
use PKP\core\Core;
use Illuminate\Support\Facades\DB;
use DateTime;

class ConfirmMembershipTask extends ScheduledTask {
    
    public function executeActions() {
        $userDao = Repo::user()->dao;
        $journalDao = DAORegistry::getDAO('PressDAO'); /* @var $journalDao JournalDAO */
        $pluginSettings = new ConfirmMembershipPlugin();
        $this->sendConfirmMailAndDisabled($userDao, $journalDao, $pluginSettings);
    }

    // Send confirm membership email to users or delete them if they have not logged in.
    private function sendConfirmMailAndDisabled($userDao, $journalDao, $pluginSettings) {

        $daysSendMail = $pluginSettings->getSetting(CONTEXT_SITE, 'daysmail');
        $daysmerged = $pluginSettings->getSetting(CONTEXT_SITE, 'daysmerged');
        $maxusers = $pluginSettings->getSetting(CONTEXT_SITE, 'maxusers');
        $roleIds = explode(',', $pluginSettings->getSetting(CONTEXT_SITE, 'roleids'));
        $mergesUser = $pluginSettings->getSetting(CONTEXT_SITE, 'mergeusername');
        $mergesUserId = $userDao->getByUsername($mergesUser)->getId();
        $timestamp = new \DateTime(Core::getCurrentDate());
        $timestamp->modify('-' . 2 . ' minutes');
        $paras = [Core::getCurrentDate(), $mergesUserId, $maxusers];
        $result = DB::select("select user_id from users  WHERE date_last_login < DATE(?) - interval ' $daysSendMail days' and disabled = 0
       and user_id != ? order by RANDOM() LIMIT ? ",
        $paras);

      //  $result = DB::select("select user_id from users where user_id = 8");
        foreach ($result as $userId) {

            $user = $userDao->get($userId->user_id);// It is not time to merge the user.
            if (!is_null($this->getUserSettings($user->getId(), ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND)) && $timestamp < new DateTime($this->getUserSettings($user->getId(), ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND))
                || $this->getUserSettings($user->getId(), ConfirmMembershipPlugin::SETTING_CAN_NOT_DELETE)) {
                continue;
            } else if (!is_null($this->getUserSettings($user->getId(), ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND)) && $timestamp > new DateTime($this->getUserSettings($user->getId(), ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND))) {
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
                $emailTemplate = Repo::emailTemplate()->getByKey(CONTEXT_SITE, 'COMFIRMMEMBERSHIP_MEMBERSHIP');
            }
            else {
                $emailTemplate =  Repo::emailTemplate()->getByKey(CONTEXT_SITE, 'COMFIRMMEMBERSHIP_NO_JOURNALS_MEMBERSHIP');//new EmailTemplate('COMFIRMMEMBERSHIP_NO_JOURNALS_MEMBERSHIP', 'en_US');
            }
            error_log(print_r($emailTemplate, true));
            $mailable = new Mailable();
            $mailable
                ->from('majr@kb.dk')
                ->body(__('confirmmembershipnojournals.emails.body'));


            if ($pluginSettings->getSetting(CONTEXT_SITE, 'test')) {
                $testmails = explode(';', $pluginSettings->getSetting(CONTEXT_SITE, 'testemails'));
                foreach ($testmails as $testmail) {
                    $mailable->to($testmail);
                }
            } else {
                $mailable->to($user->getEmail(), $user->getFullName());
            }
            $mailable->addData([
                'fullname' => $user->getFullName(), 'journal' => $journalsNames
            ]);


          if(true) {// if ( Mail::send($mailable)) {
              //$userSettingsDao = DAORegistry::getDAO('UserSettingsDAO');
              DB::insert("insert into user_settings(user_id, locale, setting_name, setting_value ) values(?,'en', ?,?) ",[$user->getId(),ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND, Core::getCurrentDate()] );
           }
           else{
               error_log('Error sending mail to user[' . $user->getId() . ']');
           }
        }
       $this->resetUsersSettins($userDao);
    }

    private function resetUsersSettins($userDao){
        $users= DB::select("select users.user_id from users, user_settings where date_last_login > NOW() - INTERVAL '356 days' and user_settings.setting_name = ? AND users.user_id = user_settings.user_id", [ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND]);
        foreach ($users as $user) {
            $user->deleteDate(ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND);
            $user->deleteDate(ConfirmMembershipPlugin::SETTING_CAN_NOT_DELETE);
        }
    }
    private function mergeUsers($userDao, $journalDao, $roleIds, $user, $mergesUserId) {

        $journals = $journalDao->getAll();
        // Find the name(s) of the journals the user is signed up for and check roles and subscriptions
        while ($journal = $journals->next()) {
            foreach ($user->getRoles($journal->getId()) as $role) {
                if (!in_array($role->getId(), $roleIds)) {
                    $this->userCantBeDeleted($user, $userDao);
                    return;
                }
            }
        }
        $this->userAction = new Repository($userDao);
//        $userAction = $this->userAction;
        $this->userAction->mergeUsers($user->getId(), $mergesUserId);
    }
    private function userCantBeDeleted (&$user, $userDao) {
        DB::insert("insert into user_settings(user_id, locale, setting_name, setting_value ) values(?,'en', ?,?) ",[$user->getId(), 'membershipcannotdelete', true ]);
        return true;
    }
    private function userHasReviews($userId) {
        $checkUserSubmissions =DB::select('SELECT count(*)  AS row_count  FROM review_assignments  where reviewer_id = ? ', [$userId]); //DB::table('stage_assignments')->where('user_id', $userId);
        $row = $checkUserSubmissions->current();
        return $row ? (boolean) $row->row_count : false;
    }
    private function userHasSubmission($userId) {
        $checkUserSubmissions = DB::select("select count(*)  AS row_count from stage_assignments where user_id = ?", [$userId]); //DB::table('stage_assignments')->where('user_id', $userId);
        $current = $checkUserSubmissions->current();
        $row = $current;
        return $row ? (boolean) $row->row_count : false;
    }
    public function getName() {
        return __('admin.scheduledTask.ConfirmMembershipTask');
    }
    private function getUserSettings($userId, $settingName)
    {
        $result = DB::select("select setting_value from user_settings where user_id = ? and setting_name = ?", [$userId, $settingName]);
        return isset($result[0]) ? $result[0]->setting_value : null;
    }
}