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

use APP\core\Services;
use APP\plugins\generic\confirmMembership\ConfirmMembershipPlugin;
use Illuminate\Support\Facades\Mail;
use PKP\locale\AppLocale;
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
        $pressDao = DAORegistry::getDAO('PressDAO'); /* @var $pressDao pressDAO */
        $pluginSettings = new ConfirmMembershipPlugin();
        $this->sendConfirmMailAndDisabled($userDao, $pressDao, $pluginSettings);
    }

    // Send confirm membership email to users or delete them if they have not logged in.
    private function sendConfirmMailAndDisabled($userDao, $pressDao, $pluginSettings) {

        $daysSendMail = $pluginSettings->getSetting(CONTEXT_SITE, 'daysmail');
        $daysmerged = $pluginSettings->getSetting(CONTEXT_SITE, 'daysmerged');
        $maxusers = $pluginSettings->getSetting(CONTEXT_SITE, 'maxusers');
        $roleIds = explode(',', $pluginSettings->getSetting(CONTEXT_SITE, 'roleids'));
        $mergesUser = $pluginSettings->getSetting(CONTEXT_SITE, 'mergeusername');
        $mergesUserId = $userDao->getByUsername($mergesUser)->getId();
        $jobsAutoDeleteAge = $pluginSettings->getSetting(CONTEXT_SITE, 'jobsautodelete');
        $timestamp = new \DateTime(Core::getCurrentDate());
        $timestamp->modify('-' . $daysmerged . ' day');
        $paras = [Core::getCurrentDate(), $mergesUserId, $maxusers];
        $result = DB::select("select user_id from users  WHERE date_last_login < DATE(?) - interval ' $daysSendMail days' and disabled = 0
        and user_id != ? order by RANDOM() LIMIT ? ",
        $paras);
        $context = Services::get('context')->get(1);
        $senderMail = $context->getData('contactEmail');

        foreach ($result as $userId) {

            $user = $userDao->get($userId->user_id);// It is not time to merge the user.
            if (!is_null($this->getUserSettings($user->getId(), ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND)) && $timestamp < new DateTime($this->getUserSettings($user->getId(), ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND))
                || $this->getUserSettings($user->getId(), ConfirmMembershipPlugin::SETTING_CAN_NOT_DELETE)) {
                continue;

            } else if (!is_null($this->getUserSettings($user->getId(), ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND)) && $timestamp > new DateTime($this->getUserSettings($user->getId(), ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND))) {
                $this->mergeUsers($userDao, $pressDao, $roleIds, $user, $mergesUserId, $jobsAutoDeleteAge);
                continue;
            }

            $presses = $pressDao->getAll();

            // Find the name(s) of the presses the user is signed up for and check roles and subscriptions
            $memberPresses = [];
            while ($press = $presses->next()) {
                error_log($press->getName($press->getPrimaryLocale()));

                foreach ($user->getRoles($press->getId()) as $role) {
                    error_log('roles her:');
                    error_log(print_r($role, true));
                    $memberPresses[] = $press->getName($press->getPrimaryLocale());
                    error_log('roles end');
                    break;
                }
            }
            $fullName = $user->getFullName() ? $user->getFullName() : $user->getGivenName('en') . ' ' . $user->getFamilyName('en');
            if (!empty($memberPresses)) {
                error_log(__('confirmmembershipnojournals.emails.subject'));
                $pressNames = implode(', ', $memberPresses);
                $mailable = new Mailable();
                $mailable
                    ->from($senderMail)
                    ->body(__('confirmmembership.emails.body', [
                        'fullname' => $fullName,
                        'press' => $pressNames
                    ], null))
                    ->subject(__('confirmmembershipnojournals.emails.subject'));
            }
            else {
                $mailable = new Mailable();
                error_log('send confirm 1');
                $mailable
                    ->from($senderMail)
                    ->body(__('confirmmembershipnojournals.emails.body', [
                        'fullname' => $fullName,
                    ], null))
                    ->subject(__('confirmmembershipnojournals.emails.subject'));
            }


            if ($pluginSettings->getSetting(CONTEXT_SITE, 'test')) {
                $testmails = explode(';', $pluginSettings->getSetting(CONTEXT_SITE, 'testemails'));
                foreach ($testmails as $testmail) {
                    $mailable->to($testmail);
                }
            } else {
                $mailable->to($user->getEmail(), $fullName);
            }
           try {
               Mail::send($mailable);
              DB::insert("insert into user_settings(user_id, locale, setting_name, setting_value ) values(?,'en', ?,?) ",[$user->getId(),ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND, Core::getCurrentDate()] );
           } catch (\Throwable $e) {
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
    private function mergeUsers($userDao, $pressDao, $roleIds, $user, $mergesUserId, $jobsAutoDeleteAge) {

        if ($this->userHasReviews($user->getId(), $jobsAutoDeleteAge) || $this->userHasSubmission($user->getId(), $jobsAutoDeleteAge) ) {
            $this->userCantBeDeleted($user, $userDao);
            return;
        }

        $presses = $pressDao->getAll();
        // Find the name(s) of the journals the user is signed up for and check roles and subscriptions
        while ($press = $presses->next()) {
            foreach ($user->getRoles($press->getId()) as $role) {
                if (!in_array($role->getId(), $roleIds)) {
                    $this->userCantBeDeleted($user, $userDao);
                    return;
                }
            }
        }
        $this->userAction = new Repository($userDao);

        $this->userAction->mergeUsers($user->getId(), $mergesUserId, $jobsAutoDeleteAge);
    }
    private function userCantBeDeleted (&$user, $userDao) {
        DB::insert("insert into user_settings(user_id, locale, setting_name, setting_value ) values(?,'en', ?,?) ",[$user->getId(), 'membershipcannotdelete', true ]);
        return true;
    }
    private function userHasReviews($userId, $jobsAutoDeleteAge) {
        $reviewersignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /** @var stageAssignmentDao StageAssignmentDAO */
        $checkUserSubmissions = $reviewersignmentDao->retrieve("SELECT count(*)  AS row_count  FROM review_assignments  where reviewer_id = ? and last_modified > DATE(?) - interval ' $jobsAutoDeleteAge days' ", [$userId, Core::getCurrentDate()]);
        $row = $checkUserSubmissions->current();
        return $row ? (boolean) $row->row_count : false;
    }
    private function userHasSubmission($userId, $jobsAutoDeleteAge) {
        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var stageAssignmentDao StageAssignmentDAO */
        $checkUserSubmissions = $stageAssignmentDao->retrieve("select count(*) AS row_count  from stage_assignments join submissions on stage_assignments.submission_id =submissions.submission_id where last_modified  >  DATE(?) - interval ' $jobsAutoDeleteAge days' and user_id = ?", [Core::getCurrentDate(), $userId]);
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