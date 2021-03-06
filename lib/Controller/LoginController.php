<?php

namespace OCA\SocialLogin\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IURLGenerator;
use OCP\IAvatarManager;
use OCP\IGroupManager;
use OC\User\LoginException;
use OCA\SocialLogin\Storage\SessionStorage;
use OCA\SocialLogin\Provider\OpenID;
use OCA\SocialLogin\Db\SocialConnectDAO;
use Hybridauth\Hybridauth;
use Hybridauth\User\Profile;
use Hybridauth\HttpClient\Curl;

class LoginController extends Controller
{
    /** @var IConfig */
    private $config;
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var SessionStorage */
    private $storage;
    /** @var IUserManager */
    private $userManager;
    /** @var IUserSession */
    private $userSession;
    /** @var IAvatarManager */
    private $avatarManager;
    /** @var IGroupManager */
    private $groupManager;
    /** @var IL10N */
    private $l;
    /** @var SocialConnectDAO */
    private $socialConnect;


    public function __construct(
        $appName,
        IRequest $request,
        IConfig $config,
        IURLGenerator $urlGenerator,
        SessionStorage $storage,
        IUserManager $userManager,
        IUserSession $userSession,
        IAvatarManager $avatarManager,
        IGroupManager $groupManager,
        IL10N $l,
        SocialConnectDAO $socialConnect
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->storage = $storage;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->avatarManager = $avatarManager;
        $this->groupManager = $groupManager;
        $this->l = $l;
        $this->socialConnect = $socialConnect;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function oauth($provider)
    {
        $config = [
            'callback' => $this->urlGenerator->linkToRouteAbsolute($this->appName.'.login.oauth', ['provider'=>$provider])
        ];
        $providers = json_decode($this->config->getAppValue($this->appName, 'oauth_providers', '[]'), true);
        foreach ($providers as $title=>$prov) {
            $idKey = in_array($title, ['twitter']) ? 'key' : 'id';
            $keys = [
                $idKey   => $prov['appid'],
                'secret' => $prov['secret'],
            ];
            $config['providers'][ucfirst($title)] = [
                'enabled' => true,
                'keys' => $keys,
                'scope' => 'email',
            ];
        }
        try {
            $auth = new Hybridauth($config, null, $this->storage);
            $adapter = $auth->authenticate(ucfirst($provider));
            $profile = $adapter->getUserProfile();
        } catch (\Exception $e) {
            throw new LoginException($e->getMessage());
        }
        $uid = $provider.'-'.$profile->identifier;

        return $this->login($uid, $profile);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function openid($provider)
    {
        $config = [
            'callback' => $this->urlGenerator->linkToRouteAbsolute($this->appName.'.login.openid', ['provider'=>$provider])
        ];
        $idUrl = null;
        $providers = json_decode($this->config->getAppValue($this->appName, 'openid_providers', '[]'), true);
        foreach ($providers as $prov) {
            if ($prov['title'] === $provider) {
                $idUrl = $prov['url'];
            }
        }
        if (!$idUrl) {
            throw new LoginException($this->l->t('Unknown OpenID provider: "%s"', $provider));
        }
        $config['openid_identifier'] = $idUrl;
        try {
            $adapter = new OpenID($config, null, $this->storage);
            $adapter->authenticate();
            $profile = $adapter->getUserProfile();
        }  catch (\Exception $e) {
            throw new LoginException($e->getMessage());
        }
        $profileId = preg_replace('#.*/#', '', rtrim($profile->identifier, '/'));
        $uid = preg_replace('#[^0-9a-z_.@-]#i', '', $provider.'-'.$profileId);
        return $this->login($uid, $profile);
    }

    private function login($uid, Profile $profile)
    {
        $user = $this->userManager->get($uid);
        if (null === $user) {
            $connectedUid = $this->socialConnect->findUID($uid);
            $user = $this->userManager->get($connectedUid);
        }
        if ($this->userSession->isLoggedIn()) {
            if (null !== $user) {
                throw new LoginException($this->l->t('This account already connected'));
            }
            $currentUid = $this->userSession->getUser()->getUID();
            $this->socialConnect->connectLogin($currentUid, $uid);
            return new RedirectResponse($this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section'=>'additional']));
        }
        if (null === $user) {
            if ($this->config->getAppValue($this->appName, 'disable_registration')) {
                throw new LoginException($this->l->t('Auto creating new users is disabled'));
            }
            $password = substr(base64_encode(random_bytes(64)), 0, 30);
            $user = $this->userManager->createUser($uid, $password);
            $user->setDisplayName((string)$profile->displayName);
            $user->setEMailAddress((string)$profile->email);

            $newUserGroup = $this->config->getAppValue($this->appName, 'new_user_group');
            if ($newUserGroup) {
                try {
                    $group = $this->groupManager->get($newUserGroup);
                    $group->addUser($user);
                } catch (\Exception $e) {}
            }

            if ($profile->photoURL) {
                $curl = new Curl();
                $photo = $curl->request($profile->photoURL);
                try {
                    $avatar = $this->avatarManager->getAvatar($uid);
                    $avatar->set($photo);
                } catch (\Exception $e) {}
            }
        }
        //No longer need. Remove leavings of previous versions.
        $this->config->deleteUserValue($uid, $this->appName, 'password');

        $this->userSession->completeLogin($user, ['loginName' => $user->getUID()], false);
        $this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID());

        return new RedirectResponse($this->urlGenerator->getAbsoluteURL('/'));
    }
}
