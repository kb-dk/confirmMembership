<?php
import('classes.handler.Handler');
import('classes.user.UserAction');
import('lib.pkp.classes.plugins.Plugin');
import('plugins.generic.RDLUser.RDLUserPlugin');
class ConfirmMembershipPluginHandler extends  Handler
{
    var $templateMgr;
    var $plugin;
    var $journalDao;
    var $userSettingsDao;
    var $subscriptionDao;
    var $instituSubscriptionDao;
    var $userDao;
    var $stageAssignmentDao;


    function __construct()
    {
        parent::__construct();
        $this->plugin = PluginRegistry::getPlugin('generic', 'confirmmembershipplugin');
        $this->journalDao = DAORegistry::getDAO('JournalDAO'); /* @var $journalDao JournalDAO */
        $this->userSettingsDao = DAORegistry::getDAO('UserSettingsDAO');
        $this->subscriptionDao = DAORegistry::getDAO('IndividualSubscriptionDAO');
        $this->instituSubscriptionDao = DAORegistry::getDAO('InstitutionalSubscriptionDAO');
        $this->stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
        $this->userDao = DAORegistry::getDAO('UserDAO');
        $this->addRoleAssignment(
            [ROLE_ID_SITE_ADMIN],
            [
                'index',
            ]
        );
    }
    /**
     * @copydoc PKPHandler::initialize()
     */
    function initialize($request, $args = null)
    {
        $this->templateMgr = TemplateManager::getManager($request);
        AppLocale::requireComponents(
            LOCALE_COMPONENT_PKP_ADMIN,
            LOCALE_COMPONENT_APP_MANAGER,
            LOCALE_COMPONENT_APP_ADMIN,
            LOCALE_COMPONENT_APP_COMMON,
            LOCALE_COMPONENT_PKP_USER,
            LOCALE_COMPONENT_PKP_MANAGER
        );
        return parent::initialize($request);
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    function authorize($request, &$args, $roleAssignments)
    {
        import('lib.pkp.classes.security.authorization.PKPSiteAccessPolicy');
        $this->addPolicy(new PKPSiteAccessPolicy($request, null, $roleAssignments));
        $returner = parent::authorize($request, $args, $roleAssignments);
        // Admin shouldn't access this page from a specific context
        if ($request->getContext()) {
            return false;
        }
        return $returner;
    }

    public function index($args, $request) {
        error_log(print_r($request->_requestVars, true));
        $amountOfUsers = $this->plugin->getSetting(CONTEXT_SITE, 'amountofusers');
        if (isset($request->_requestVars['next'])) {
            $next = $amountOfUsers + ($request->_requestVars['next']);
        }
        elseif (isset($request->_requestVars['previous'])) {
            $next = ($request->_requestVars['previous']) - $amountOfUsers;
        }
        else {
            $next = $amountOfUsers;
        }
        $users = $this->getUsersToDelete($next);

        if (isset($request->_requestVars['userid'])) {
            $userDao = DAORegistry::getDAO('UserDAO'); /* @var $userDao UserDAO */
            $mergesUserId = $userDao->getByUsername($this->plugin->getSetting(CONTEXT_SITE, 'mergeusername'))->getId();
            $userAction = new UserAction();
            $userAction->mergeUsers($request->_requestVars['userid'], $mergesUserId);
        }

        $this->templateMgr->setupBackendPage();

        $this->templateMgr->assign([
            'total' => $this->getTotalCount(),
            'users' => $users,
            'next' => $next,
            'show' => $amountOfUsers,
            'mergesUser' => __("plugins.generic.confirmmembership.mergeuserpopup", ['merge_user' => $this->plugin->getSetting(CONTEXT_SITE, 'mergeusername')]),
        ]);
        return $this->templateMgr->display($this->plugin->getTemplateResource('index.tpl'));
    }

    protected function getTotalCount()
    {
        $userSettingsDao = DAORegistry::getDAO('UserSettingsDAO');
        $result = $userSettingsDao->retrieve("select count(*) from user_settings WHERE setting_name = ?", [SETTING_CAN_NOT_DELETE]);
        return $result->current()->count;
    }

    protected function findAssignment($userId) {
        $assignment = [];
          $checkUserSubmissions = $this->stageAssignmentDao->retrieve("select submissions.submission_id, path from journals join submissions on submissions.context_id = journals.journal_id join stage_assignments on submissions.submission_id = stage_assignments.submission_id and user_id= ?", [$userId]);
        foreach ($checkUserSubmissions as $submission) {
            $assigment[] =  '/index.php/' . $submission->path . '/workflow/index/' . $submission->submission_id . '/1';;
        }
        if (!empty($assignment)) {
            $review = $assignment;
        } else {
            $review = [];
        }
        $checkUserSubmissions = $this->stageAssignmentDao->retrieve("select submissions.submission_id, path from journals join submissions on submissions.context_id = journals.journal_id join review_assignments on submissions.submission_id = review_assignments.submission_id and reviewer_id= ?",  [$userId]);
        foreach ($checkUserSubmissions as $submission) {
            $review[] = '/index.php/' . $submission->path . '/workflow/index/' . $submission->submission_id . '/1';
        }
        return $review;
     }

    private function getUsersToDelete($next) {
        $roleNames = Application::getRoleNames();
        $amountOfUsers = $this->plugin->getSetting(CONTEXT_SITE, 'amountofusers');
        $offset = $next === $amountOfUsers? 0 : ($next - $amountOfUsers);
        $result = $this->userSettingsDao->retrieve("select user_id from user_settings WHERE setting_name = ? LIMIT ? OFFSET ? ",
        [SETTING_CAN_NOT_DELETE, $amountOfUsers, $offset]);
        $deletUsers = [];
        foreach ($result as $userId) {
            $user = $this->userDao->getById($userId->user_id);
            $deletUser = [];
            $journals = $this->journalDao->getAll();
            while ($journal = $journals->next()) {
                $memberJournals = NULL;
                $roles = [];
                foreach ($user->getRoles($journal->getId()) as $role) {
                    $memberJournals =  $journal->getName($journal->getPrimaryLocale());
                    $roles[] = __($roleNames[$role->getRoleId()]);
                }
                if (!is_null($memberJournals)) {
                    if ($this->subscriptionDao->subscriptionExistsByUserForJournal($user->getId(), $journal->getId()) || $this->instituSubscriptionDao->subscriptionExistsByUserForJournal($user->getId(), $journal->getId())) {
                        $deletUser['subscriber'] = true;
                    } else {
                        $deletUser['subscriber'] = false;
                    }
                    $deletUser['journals'] = $memberJournals;
                    $deletUser['name'] = $user->getFullName();
                    $deletUser['email'] = $user->getEmail();
                    $deletUser['userid'] = $user->getId();
                    $deletUser['role'] = implode(', ', $roles);
                    $deletUser['journals'] = $memberJournals;
                    $deletUser['link'] = '/index.php/' . $journal->getPath() . '/management/settings/access?email=' .  $user->getEmail();
                    $deletUser['assignment'] = $this->findAssignment($user->getId());
                    $deletUsers[] = $deletUser;
                }
            }
            if (count($deletUser) == 0) {
                $deletUser['journals'] = 'none';
                $deletUser['subscriber'] = false;
                $deletUser['assignment'] = $this->findAssignment($user->getId());
                $deletUser['name'] = $user->getFullName();
                $deletUser['email'] = $user->getEmail();
                $deletUser['role'] = '';
                $deletUser['link'] = '';
                if ($this->subscriptionDao->subscriptionExistsByUserForJournal($user->getId(), $journal->getId()) || $this->instituSubscriptionDao->subscriptionExistsByUserForJournal($user->getId(), $journal->getId())) {
                    $deletUser['subscriber'] = true;
                } else {
                    $deletUser['subscriber'] = false;
                }
                $deletUser['userid'] = $user->getId();
                $deletUsers[] = $deletUser;
            }
        }
        return $deletUsers;
    }
}