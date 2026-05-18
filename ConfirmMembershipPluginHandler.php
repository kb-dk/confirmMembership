<?php

namespace APP\plugins\generic\confirmMembership;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\PKPSiteAccessPolicy;
use Illuminate\Support\Facades\DB;
use PKP\security\Role;


class ConfirmMembershipPluginHandler extends Handler
{
    public $templateMgr;
    public $plugin;
    public $journalDao;
    public $subscriptionDao;
    public $instituSubscriptionDao;

    public function __construct()
    {
        parent::__construct();
        $this->plugin = PluginRegistry::getPlugin('generic', 'confirmmembershipplugin');
        $this->journalDao = DAORegistry::getDAO('JournalDAO');
        $this->subscriptionDao = DAORegistry::getDAO('IndividualSubscriptionDAO');
        $this->instituSubscriptionDao = DAORegistry::getDAO('InstitutionalSubscriptionDAO');
        $this->addRoleAssignment(
            [Role::ROLE_ID_SITE_ADMIN], ['index',]
        );
    }

    /**
     * @copydoc PKPHandler::initialize()
     */

    public function initialize($request, $args = null)
    {
        $this->templateMgr = TemplateManager::getManager($request);
        return parent::initialize($request);
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new PKPSiteAccessPolicy($request, null, $roleAssignments));
        $returner = parent::authorize($request, $args, $roleAssignments);
        // Admin shouldn't access this page from a specific context
        if ($request->getContext()) {
            return false;
        }
        return $returner;
    }

    public function index($args, $request)
    {
        $this->templateMgr = TemplateManager::getManager($request);
        $this->templateMgr->assign('pageTitle', 'My Admin Page');
        $this->_isBackendPage = true;
        $amountOfUsers = (int) $this->plugin->getSetting(CONTEXT_SITE, 'amountofusers');
        $userId = $request->getUserVar('userid');
        if ($userId) {
            $mergeUsername = $this->plugin->getSetting(CONTEXT_SITE, 'mergeusername');
            $mergeUser = Repo::user()->getByUsername($mergeUsername);
            if ($mergeUser) {
                Repo::user()->mergeUsers((int) $userId, $mergeUser->getId());
            }
        }

        // Determine the next offset for pagination
        if ($request->getUserVar('next')) {
            $next = $amountOfUsers + (int) $request->getUserVar('next');
        } elseif ($request->getUserVar('previous')) {
            $next = (int) $request->getUserVar('previous') - $amountOfUsers;
        } else {
            $next = $amountOfUsers;
        }

        // Fetch users to delete
        $users = $this->getUsersToDelete($next);


        $this->templateMgr->assign([
            'total' => $this->getTotalCount(),
            'users' => $users,
            'next' => $next,
            'show' => $amountOfUsers,
            'mergesUser' => __('plugins.generic.confirmmembership.mergeuserpopup',
                ['merge_user' => $this->plugin->getSetting(CONTEXT_SITE, 'mergeusername')]
            ),
        ]);
        // Display the template
        $this->templateMgr->display($this->plugin->getTemplateResource('index.tpl')
        );
    }
    protected function getTotalCount()
    {
        $result = DB::table('user_settings')
            ->where('setting_name', SETTING_CAN_NOT_DELETE)
            ->count();

        return $result;
    }

    protected function findAssignment($userId)
    {
        $review = [];

        // Stage assignments
        $stageAssignments = DB::select(
            "SELECT submissions.submission_id, journals.path, submissions.last_modified::date
             FROM journals
             JOIN submissions ON submissions.context_id = journals.journal_id
             JOIN stage_assignments ON submissions.submission_id = stage_assignments.submission_id
             WHERE stage_assignments.user_id = ?",
            [$userId]
        );

        foreach ($stageAssignments as $submission) {
            $review[] = [
                'url' => '/index.php/' . $submission->path . '/workflow/index/' . $submission->submission_id . '/1',
                'date' => $submission->last_modified,
                'review' => false
            ];
        }

        // Review assignments
        $reviewAssignments = DB::select(
            "SELECT submissions.submission_id, journals.path, review_assignments.last_modified::date
             FROM journals
             JOIN submissions ON submissions.context_id = journals.journal_id
             JOIN review_assignments ON submissions.submission_id = review_assignments.submission_id
             WHERE review_assignments.reviewer_id = ?",
            [$userId]
        );

        foreach ($reviewAssignments as $submission) {
            $review[] = [
                'url' => '/index.php/' . $submission->path . '/workflow/index/' . $submission->submission_id . '/1',
                'date' => $submission->last_modified,
                'review' => true
            ];
        }

        return $review;
    }


    private function getUsersToDelete($next)
    {
        $roleNames = Application::getRoleNames();
        $amountOfUsers = $this->plugin->getSetting(PKPApplication::CONTEXT_SITE, 'amountofusers');
        $offset = $next === $amountOfUsers ? 0 : ($next - $amountOfUsers);

        $userIds = DB::select(
            "SELECT us1.user_id
         FROM user_settings us1
         JOIN users ON users.user_id = us1.user_id
         WHERE us1.setting_name = ?
         AND us1.user_id IN (
             SELECT user_id FROM user_settings WHERE setting_name = ?
         )
         ORDER BY us1.setting_value DESC
         LIMIT ? OFFSET ?",
            [SETTING_MEMBERSHIP_MAIL_SEND, SETTING_CAN_NOT_DELETE, $amountOfUsers, $offset]
        );

        $deletUsers = [];

        // Get contexts using DAO
        $contextDao = Application::getContextDAO();
        $contextsIterator = $contextDao->getAll(true);
        $contexts = $contextsIterator->toArray();
        foreach ($userIds as $userIdObj) {
            $user = Repo::user()->get($userIdObj->user_id);
            if (!$user) continue;

            $deletUser = [];
            $deletUser['subscriber'] = false;
            $hasJournalMembership = false;

            foreach ($contexts as $journal) {
                // Check subscription status
                if ($this->subscriptionDao->subscriptionExistsByUserForJournal($user->getId(), $journal->getId())
                    || $this->instituSubscriptionDao->subscriptionExistsByUserForJournal($user->getId(), $journal->getId())) {
                    $deletUser['subscriber'] = true;
                }

                $memberJournals = null;
                $roles = [];

                $userGroups = Repo::userGroup()->userUserGroups($user->getId(), $journal->getId());

                foreach ($userGroups as $userGroup) {
                    $memberJournals = $journal->getLocalizedName();
                    $roleId = $userGroup->roleId; // Changed from getRoleId() to property access
                    if (isset($roleNames[$roleId])) {
                        $roles[] = __($roleNames[$roleId]);
                    }
                }

                if (!is_null($memberJournals)) {
                    $deletUser['journals'] = $memberJournals;
                    $deletUser['name'] = $user->getFullName();
                    $deletUser['username'] = $user->getUsername();
                    $deletUser['email'] = $user->getEmail();
                    $deletUser['userid'] = $user->getId();
                    $deletUser['role'] = implode(', ', $roles);
                    $deletUser['link'] = '/index.php/' . $journal->getData('urlPath') . '/management/settings/access';
                    $deletUser['assignment'] = $this->findAssignment($user->getId());
                    $deletUser['date'] = $user->getData(SETTING_MEMBERSHIP_MAIL_SEND);
                    $deletUsers[] = $deletUser;
                    $hasJournalMembership = true;
                }
            }

            // If user has no journals (only subscriber flag set)
            if (!$hasJournalMembership && count($deletUser) == 1) {
                $deletUser['journals'] = 'none';
                $deletUser['assignment'] = $this->findAssignment($user->getId());
                $deletUser['name'] = $user->getFullName();
                $deletUser['username'] = $user->getUsername();
                $deletUser['email'] = $user->getEmail();
                $deletUser['role'] = '';
                $deletUser['link'] = '';
                $deletUser['userid'] = $user->getId();
                $deletUser['date'] = $user->getData(SETTING_MEMBERSHIP_MAIL_SEND);
                $deletUsers[] = $deletUser;
            }
        }

        return $deletUsers;
    }

}
