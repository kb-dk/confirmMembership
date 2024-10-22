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
        $amountOfUsers = $this->plugin->getSetting(CONTEXT_SITE, 'amountofusers');
        if (isset($request->_requestVars['userid'])) {

            $userDao = DAORegistry::getDAO('UserDAO'); /* @var $userDao UserDAO */
            $mergesUserId = $userDao->getByUsername($this->plugin->getSetting(CONTEXT_SITE, 'mergeusername'))->getId();
            $userAction = new UserAction();

            $userAction->mergeUsers($request->_requestVars['userid'], $mergesUserId);

        }
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
        $review = [];
          $checkUserSubmissions = $this->stageAssignmentDao->retrieve("select submissions.submission_id, path, last_modified::date from journals join submissions on submissions.context_id = journals.journal_id join stage_assignments on submissions.submission_id = stage_assignments.submission_id and user_id= ?", [$userId]);
        foreach ($checkUserSubmissions as $submission) {
            $review[] = [
                'url' => '/index.php/' . $submission->path . '/workflow/index/' . $submission->submission_id . '/1',
                'date' => $submission->last_modified,
                'review' => false
            ];
        }
        $checkUserSubmissions = $this->stageAssignmentDao->retrieve("select submissions.submission_id, path , review_assignments.last_modified::date  from journals join submissions on submissions.context_id = journals.journal_id join review_assignments on submissions.submission_id = review_assignments.submission_id and reviewer_id= ?",  [$userId]);
        foreach ($checkUserSubmissions as $submission) {
            $review[] = [
                'url' => '/index.php/' . $submission->path . '/workflow/index/' . $submission->submission_id . '/1',
                'date' => $submission->last_modified,
                'review' => true
            ];

        }
        return $review;
     }

    private function getUsersToDelete($next) {
        $roleNames = Application::getRoleNames();
        $amountOfUsers = $this->plugin->getSetting(CONTEXT_SITE, 'amountofusers');
        $offset = $next === $amountOfUsers? 0 : ($next - $amountOfUsers);
        $result = $this->userSettingsDao->retrieve("select user_settings.user_id from user_settings join users on users.user_id=user_settings.user_id  where setting_name=? and user_settings.user_id in (select user_id from user_settings where setting_name=?) order by setting_value DESC LIMIT ? OFFSET ?",
        [SETTING_MEMBERSHIP_MAIL_SEND, SETTING_CAN_NOT_DELETE, $amountOfUsers, $offset]);
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
                    $deletUser['username'] = $user->getUsername();
                    $deletUser['email'] = $user->getEmail();
                    $deletUser['userid'] = $user->getId();
                    $deletUser['role'] = implode(', ', $roles);
                    $deletUser['journals'] = $memberJournals;
                    $deletUser['link'] = '/index.php/' . $journal->getPath() . '/management/settings/access';
                    $deletUser['assignment'] = $this->findAssignment($user->getId());
                    $deletUser['date'] = $user->getData(SETTING_MEMBERSHIP_MAIL_SEND);
                    $deletUsers[] = $deletUser;
                }
            }
            if (count($deletUser) == 0) {
                $deletUser['journals'] = 'none';
                $deletUser['subscriber'] = false;
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