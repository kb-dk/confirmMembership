<?php

namespace APP\plugins\generic\confirmmembership;

use APP\plugins\generic\confirmMembership\ConfirmMembershipPlugin;
use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPRequest;
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

    /** @var UserSettingsDAO */
    protected $userSettingsDao;


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
//
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
//        AppLocale::requireComponents(
//            LOCALE_COMPONENT_PKP_ADMIN,
//            LOCALE_COMPONENT_APP_MANAGER,
//            LOCALE_COMPONENT_APP_ADMIN,
//            LOCALE_COMPONENT_APP_COMMON,
//            LOCALE_COMPONENT_PKP_USER,
//            LOCALE_COMPONENT_PKP_MANAGER
//        );
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
        //$userRoles = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
        //$this->templateMgr->setUserRoles($userRoles);
        $this->_isBackendPage = true;
        $this->templateMgr->setupBackendPage();
        //$this->_isBackendPage = true;
        //$this->templateMgr->setupBackendPage();
        error_log('index');

        error_log('userRoles:');
        error_log(print_r($userRoles, true));
        $this->templateMgr->assign('userRoles', $userRoles);
        $amountOfUsers = (int) $this->plugin->getSetting(CONTEXT_SITE, 'amountofusers');
        error_log('index 1');
        $userId = $request->getUserVar('userid');
        error_log('index 3');
        if ($userId) {
            $mergeUsername = $this->plugin->getSetting(CONTEXT_SITE, 'mergeusername');
            $mergeUser = $this->userDao->getByUsername($mergeUsername);

            if ($mergeUser) {
                $userAction = new UserAction();
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

        // Setup the template for the backend page
       //  $this->templateMgr->setupBackendPage();


        $this->templateMgr->assign([
            'userRoles'=> $userRoles,
            'total' => $this->getTotalCount(),
            'users' => $users,
            'next' => $next,
            'show' => $amountOfUsers,
            'mergesUser' => __('plugins.generic.confirmmembership.mergeuserpopup',
                ['merge_user' => $this->plugin->getSetting(CONTEXT_SITE, 'mergeusername')]
            ),
        ]);
       error_log( $this->plugin->getTemplateResource('index.tpl'));
        // Display the template
         $this->templateMgr->display($this->plugin->getTemplateResource('index.tpl')
        );
    }

    protected function getTotalCount(): int
    {
        error_log(ConfirmMembershipPlugin::SETTING_CAN_NOT_DELETE);
        $result = DB::select(
            'SELECT COUNT(*) FROM user_settings WHERE setting_name = ?',
            [ConfirmMembershipPlugin::SETTING_CAN_NOT_DELETE]
        );
        error_log(print_r($result, true));
        return (int) $result[0]->count;
    }

    protected function findAssignment(int $userId): array
    {
        $assignments = [];

        // Fetching stage assignments for the user
        $result = DB::select(
            'SELECT s.submission_id, p.path, sa.date_assigned::date
         FROM presses p
         JOIN submissions s ON s.context_id = p.press_id
         JOIN stage_assignments sa ON sa.submission_id = s.submission_id
         WHERE sa.user_id = ?',
            [$userId]
        );

        foreach ($result as $row) {
            $assignments[] = [
                'url' => '/index.php/' . $row->path . '/workflow/index/' . $row->submission_id . '/1',
                'date' => $row->date_assigned,
                'review' => false,
            ];
        }

        // Fetching review assignments for the user
        $result = $this->stageAssignmentDao->retrieve(
            'SELECT s.submission_id, p.path, ra.last_modified::date
         FROM presses p
         JOIN submissions s ON s.context_id = p.press_id
         JOIN review_assignments ra ON ra.submission_id = s.submission_id
         WHERE ra.reviewer_id = ?',
            [$userId]
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
                    'subscriber' => false,
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

