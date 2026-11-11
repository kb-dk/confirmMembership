<?php

namespace APP\plugins\generic\confirmmembership;

use APP\plugins\generic\confirmMembership\ConfirmMembershipPlugin;
use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use Illuminate\Support\Facades\DB;
use APP\log\event\SubmissionEventLogEntry;
use PKP\user\Repository;
use PKP\lib\pkp\classes\plugins\Plugin;
use PKP\classes\user\UserAction;
use PKP\classes\core\AppLocale;
use PKP\plugins\PluginRegistry;
use PKP\db\DAORegistry;
use PKP\security\Role;
use APP\template\TemplateManager;
use PKP\security\authorization\PKPSiteAccessPolicy;

class ConfirmMembershipPluginHandler extends Handler
{
    /** @var TemplateManager */
    protected $templateMgr;

    /** @var Plugin */
    protected $plugin;

    /** @var pressDAO */
    protected $pressDao;

    /** @var UserDAO */
    protected $userDao;

    /** @var StageAssignmentDAO */
    protected $stageAssignmentDao;

    public function __construct()
    {
        parent::__construct();

        $this->plugin = PluginRegistry::getPlugin('generic', 'confirmmembershipplugin');
        $this->userDao = Repo::user()->dao;
        $this->pressDao = \PKP\db\DAORegistry::getDAO('PressDAO');
        $this->stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
        $this->addRoleAssignment([Role::ROLE_ID_SITE_ADMIN], ['index']);

    }

    /**
     * @copydoc PKPHandler::initialize()
     */
    public function initialize($request, $args = null)
    {
        parent::initialize($request, $args);
        $this->templateMgr = TemplateManager::getManager($request);
        $userRoles = (array) $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
        $this->templateMgr->assign(['userRoles' => $userRoles]);
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new PKPSiteAccessPolicy($request, null, $roleAssignments));

        if ($request->getContext()) {
            return false;
        }

        return parent::authorize($request, $args, $roleAssignments);
    }

    public function index($args, $request) {
        $this->templateMgr = TemplateManager::getManager($request);
        $this->templateMgr->assign('pageTitle', 'My Admin Page');
        $this->_isBackendPage = true;
        $this->templateMgr->setupBackendPage();
        $amountOfUsers = (int) $this->plugin->getSetting(CONTEXT_SITE, 'amountofusers');
        $userId = $request->getUserVar('userid');
        if ($userId) {
            $mergeUsername = $this->plugin->getSetting(CONTEXT_SITE, 'mergeusername');
            $mergeUser = $this->userDao->getByUsername($mergeUsername);
            if ($mergeUser) {
                $userDao = Repo::user()->dao;
                $userAction =  new Repository($userDao);
                $userAction->mergeUsers((int) $userId, $mergeUser->getId());
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
            'pluginCss' => (string) file_get_contents(__DIR__ . '/css/confirmMembership.css'),
            'mergesUser' => __('plugins.generic.confirmmembership.mergeuserpopup',
                ['merge_user' => $this->plugin->getSetting(CONTEXT_SITE, 'mergeusername')]
            ),
        ]);
        // Display the template
         $this->templateMgr->display($this->plugin->getTemplateResource('index.tpl')
        );
    }

    protected function getTotalCount(): int
    {
        $result = DB::select(
            'SELECT COUNT(*) FROM user_settings WHERE setting_name = ?',
            [ConfirmMembershipPlugin::SETTING_CAN_NOT_DELETE]
        );
        return (int) $result[0]->count;
    }

    protected function findAssignment(int $userId): array
    {
        $assignments = [];

        // Latest event_log entry made by the user on a submission, including events on its files.
        // Used as "last activity" date. Parameters: ASSOC_TYPE_SUBMISSION, ASSOC_TYPE_SUBMISSION_FILE, user id.
        $lastActivitySql = '(SELECT MAX(el.date_logged)
              FROM event_log el
              WHERE el.user_id = ?
                AND (
                    (el.assoc_type = ? AND el.assoc_id = s.submission_id)
                    OR (el.assoc_type = ? AND el.assoc_id IN (
                        SELECT sf.submission_file_id FROM submission_files sf WHERE sf.submission_id = s.submission_id
                    ))
                ))';
        $lastActivityParams = [
            $userId,
            Application::ASSOC_TYPE_SUBMISSION,
            Application::ASSOC_TYPE_SUBMISSION_FILE,
        ];

        // Fetching stage assignments for the user.
        // Date = latest event_log entry by the user on the submission; falls back to the
        // assignment date if the user has no log entries.
        $result = DB::select(
            'SELECT s.submission_id, p.path,
                COALESCE(' . $lastActivitySql . ', MIN(sa.date_assigned))::date AS last_modified
         FROM presses p
         JOIN submissions s ON s.context_id = p.press_id
         JOIN stage_assignments sa ON sa.submission_id = s.submission_id
         WHERE sa.user_id = ?
         GROUP BY s.submission_id, p.path',
            [...$lastActivityParams, $userId]
        );

        foreach ($result as $row) {
            $assignments[] = [
                'url' => '/index.php/' . $row->path . '/workflow/index/' . $row->submission_id . '/1',
                'date' => $row->last_modified,
                'review' => false,
            ];
        }

        // Fetching review assignments for the user.
        // Declined review: date of the decline event (fallback: review_assignments.date_confirmed).
        // Otherwise: latest event_log entry by the user on the submission (fallback: review_assignments.last_modified).
        $result = DB::select(
            'SELECT s.submission_id, p.path,
                (CASE WHEN ra.declined = 1 THEN
                    COALESCE(
                        (SELECT MAX(el.date_logged)
                           FROM event_log el
                           JOIN event_log_settings els ON els.log_id = el.log_id
                          WHERE el.event_type = ?
                            AND el.assoc_type = ?
                            AND el.assoc_id = s.submission_id
                            AND els.setting_name = \'reviewAssignmentId\'
                            AND els.setting_value = ra.review_id::text),
                        ra.date_confirmed,
                        ra.last_modified
                    )
                 ELSE
                    COALESCE(' . $lastActivitySql . ', ra.last_modified)
                 END)::date AS last_modified
         FROM presses p
         JOIN submissions s ON s.context_id = p.press_id
         JOIN review_assignments ra ON ra.submission_id = s.submission_id
         WHERE ra.reviewer_id = ?',
            [
                SubmissionEventLogEntry::SUBMISSION_LOG_REVIEW_DECLINE,
                Application::ASSOC_TYPE_SUBMISSION,
                ...$lastActivityParams,
                $userId,
            ]
        );

        foreach ($result as $row) {
            $assignments[] = [
                'url' => '/index.php/' . $row->path . '/workflow/index/' . $row->submission_id . '/1',
                'date' => $row->last_modified,
                'review' => true,
            ];
        }

        return $assignments;
    }

    private function getUsersToDelete(int $next): array
    {
        $roleNames =  Application::get()->getRoleNames();
        $amountOfUsers = (int) $this->plugin->getSetting(CONTEXT_SITE, 'amountofusers');

        $offset = ($next === $amountOfUsers) ? 0 : ($next - $amountOfUsers);

        $result =  DB::select(
            'SELECT us.user_id
         FROM user_settings us
         JOIN users u ON u.user_id = us.user_id
         WHERE us.setting_name = ?
           AND us.user_id IN (
               SELECT user_id FROM user_settings WHERE setting_name = ?
           )
         ORDER BY us.setting_value DESC
         LIMIT ? OFFSET ?',
            [
                ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND,
                ConfirmMembershipPlugin::SETTING_CAN_NOT_DELETE,
                $amountOfUsers,
                $offset,
            ]
        );

        $deleteUsers = [];

        foreach ($result as $row) {
            $user = $this->userDao->get($row->user_id);
            if (!$user) {
                continue;
            }

            $presses = $this->pressDao->getAll();
            $userData = [];

            while ($press = $presses->next()) {
                $roles = [];

                foreach ($user->getRoles($press->getId()) as $role) {
                    $roles[] = __($roleNames[$role->getRoleId()]);
                }

                if (!$roles) {
                    continue;
                }

                $userData = [
                    'presses' => $press->getName($press->getPrimaryLocale()),
                    'name' => $user->getFullName(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'userid' => $user->getId(),
                    'role' => implode(', ', $roles),
                    'link' => '/index.php/' . $press->getPath() . '/management/settings/access',
                    'assignment' => $this->findAssignment($user->getId()),
                    'date' => ConfirmMembershipPlugin::getUserSettings($user->getId(), ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND),
                ];

                $deleteUsers[] = $userData;
            }

            if (empty($userData)) {
                $deleteUsers[] = [
                    'presses' => 'none',
                    'assignment' => $this->findAssignment($user->getId()),
                    'name' => $user->getFullName(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'role' => '',
                    'link' => '',
                    'userid' => $user->getId(),
                    'date' => ConfirmMembershipPlugin::getUserSettings($user->getId(), ConfirmMembershipPlugin::SETTING_MEMBERSHIP_MAIL_SEND),
                ];
            }
        }

        return $deleteUsers;
    }
}

