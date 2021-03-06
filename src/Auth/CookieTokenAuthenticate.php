<?php

namespace Beskhue\CookieTokenAuth\Auth;

use Cake\Auth\BaseAuthenticate;
use Cake\Controller\ComponentRegistry;
use Cake\Event\Event;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Auth\DefaultPasswordHasher;
use Cake\ORM\TableRegistry;
use Beskhue\CookieTokenAuth\Controller\Component\CookieTokenComponent;

/**
 * Class CookieTokenAuthenticate
 * @package Beskhue\CookieTokenAuth\Auth
 */
class CookieTokenAuthenticate extends BaseAuthenticate
{

    /**
     * This authenticate class can listen to following events fired by AuthComponent:
     *
     * - `Auth.afterIdentify` - Fired after a user has been identified using one of
     *   configured authenticate class. The callback function should have signature
     *   like `afterIdentify(Event $event, array $user)` when `$user` is the
     *   identified user record.
     *
     * - `Auth.logout` - Fired when AuthComponent::logout() is called. The callback
     *   function should have signature like `logout(Event $event, array $user)`
     *   where `$user` is the user about to be logged out.
     *
     * @var array List of events this class listens to. Defaults this class listens
     *            - `Auth.logout` - The callback remove the CookieToken if set.
     */
    protected $_implementedEvents = [
        'Auth.logout' => 'logout',
    ];

    /**
     * Constructor
     *
     * - `minimizeCookieExposureRedirectCallback` - A callable taking request and response paramaters,
     * and returning a boolean (true to proceed with the minimize cookie exposure redirection, if not
     * already performed this session, or false to bypass it).
     *
     * @param ComponentRegistry $registry The Component registry used on this request.
     * @param array $config Array of config to use.
     */
    public function __construct(ComponentRegistry $registry, array $config = [])
    {
        $this->_defaultConfig = array_merge($this->_defaultConfig, [
            'hash' => 'sha256', // Only for generating tokens -- the token stored in the database is hashed with the DefaultPasswordHasher
            'cookie' => [
                'name' => 'userdata',
                'expires' => '+10 weeks',
                'encryption' => 'aes',
                'httpOnly' => true
            ],
            'minimizeCookieExposure' => true,
            'minimizeCookieExposureRedirectCallback' => function(ServerRequest $request, Response $response) {
                return true;
            },
            /**
             * Sometimes the CookieToken should not be set afterIdentify.
             * e.g. : the 'Remember me' use case.
             */
            'setCookieAfterIdentify' => true,
            'tokenError' => __('A session token mismatch was detected. You have been logged out.')
        ]);

        parent::__construct($registry, $config);

        if ($this->getConfig('setCookieAfterIdentify')) {
            $this->_implementedEvents['Auth.afterIdentify'] = 'afterIdentify';
        }
    }

    /**
     * Get the cookie token component.
     *
     * @return CookieTokenComponent The cookie token component.
     */
    public function getCookieTokenComponent()
    {
        return $this->_registry->load('Beskhue/CookieTokenAuth.CookieToken', $this->_config);
    }

    /**
     * Authenticate a user based on the request information.
     *
     * @param ServerRequest $request Request to get authentication information from.
     * @param Response $response A response object that can have headers added.
     *
     * @return mixed Either false on failure, or an array of user data on success.
     */
    public function authenticate(ServerRequest $request, Response $response)
    {
        // Only attempt to authenticate once per session
        if (!$this->authenticateAttemptedThisSession($request)) {
            
            // The minimizeCookieExposureRedirect config is a callable object. It should return
            // a boolean value to control whether or not the minimize cookie exposure
            // redirection is performed or not.
            if ($this->config('minimizeCookieExposure') && $this->config('minimizeCookieExposureRedirectCallback')($request, $response)) {
                // We are minimizing token cookie exposure; redirect the user (once, at the start
                // of a session, to attempt to log them in using a token cookie).
                $redirectComponent = $this->_registry->load('Beskhue/CookieTokenAuth.Redirect');

                $controller = $request->getParam('controller');
                if (!$this->authenticateAttemptedThisSession($request)) {
                    if ($controller === 'CookieTokenAuth') {
                        $this->setAuthenticateAttemptedThisSession($request);
                        if ($user = $this->getUser($request)) {
                            $redirectComponent->redirectBack($request, $response);
                            return $user;
                        } else {
                            $redirectComponent->redirectBack($request, $response);
                            return false;
                        }
                    } else {
                        // We are attempting to authenticate using the token cookie, but are not
                        // on the authentication page. Redirect the user.
                        $redirectComponent->redirectToAuthenticationPage();
                        return false;
                    }
                }
            } else {
                // We are not minimizing token cookie exposure; just attempt to authenticate the user.
                $this->setAuthenticateAttemptedThisSession($request);
                if ($user = $this->getUser($request)) {
                    return $user;
                } else {
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * Get whether an authentication (on the CookieTokenAuth page) has been
     * attempted this session.
     *
     * @param ServerRequest $request Request to get session from.
     *
     * @return bool True if an authentication has been attempted this session,
     *              false otherwise.
     */
    public function authenticateAttemptedThisSession(ServerRequest $request)
    {
        $session = $request->session();
        return (bool)$session->read('CookieTokenAuth.attempted');
    }

    /**
     * Set the authenticate attempted session flag.
     *
     * @param ServerRequest $request Request to get session from.
     */
    private function setAuthenticateAttemptedThisSession(ServerRequest $request)
    {
        $session = $request->session();
        $session->write('CookieTokenAuth.attempted', true);
    }

    /**
     * Get a user based on information in the request. Primarily used by stateless authentication
     * systems like basic and digest auth.
     *
     * @param ServerRequest $request Request object.
     *
     * @return mixed Either false or an array of user information
     */
    public function getUser(ServerRequest $request)
    {
        return $this->getUserFromCookieData();
    }

    /**
     * Get a user based on cookie data.
     *
     * @return mixed Either false or an array of user information
     */
    private function getUserFromCookieData()
    {
        $cookieTokenComponent = $this->getCookieTokenComponent();
        $flashComponent = $this->_registry->load('Flash');
        $authTokens = TableRegistry::get('Beskhue/CookieTokenAuth.AuthTokens', $this->_config);

        $authTokens->removeExpired();

        $data = $this->getCookieData();
        if (!$data) {
            return false;
        }

        $series = $data['series'];
        $token = $data['token'];

        $tokenEntity = $authTokens->findBySeries($series)->contain($this->_config['userModel'])->first();
        if (!$tokenEntity) {
            // The series was not found.
            $cookieTokenComponent->removeCookie();

            return false;
        }

        $user = $tokenEntity->user;

        if (!(new DefaultPasswordHasher())->check($token, $tokenEntity->token)) {
            // Tokens don't match. Probably attempted theft!
            $flashComponent->error($this->getConfig('tokenError'), ['key' => 'auth']);
            $authTokens->deleteAllByUser($user);
            $cookieTokenComponent->removeCookie();

            return false;
        }

        // Generate new token
        $cookieTokenComponent->setCookie($user->id, $tokenEntity);

        return $this->_findUser($user->{$this->_config['fields']['username']});
    }

    /**
     * Called when the user logs out. Remove the token from the database and
     * delete the cookie.
     *
     * @param Event $event The logout event.
     * @param array $user The user data.
     */
    public function logout(Event $event, array $user)
    {
        $cookieTokenComponent = $this->getCookieTokenComponent();
        $authTokens = TableRegistry::get('Beskhue/CookieTokenAuth.AuthTokens', $this->_config);

        // Check if cookie is valid
        if ($this->getUserFromCookieData()) {
            // Remove token from database
            $data = $this->getCookieData();
            if ($data) {
                $series = $data['series'];

                $tokenEntity = $authTokens->findBySeries($series)->first();
                $authTokens->delete($tokenEntity);
            }
        }

        // Remove cookie
        $cookieTokenComponent->removeCookie();
    }

    /**
     * Called after the user is identified by an authentication adapter.
     * Sets a cookie token if the user was identified by an adapter other
     * than this one (i.e. an adapter that is not CookieTokenAuthenticate).
     *
     * @param Event $event The afterIdentify event.
     * @param array $user The user data.
     * @param \Cake\Auth\BaseAuthenticate $auth The authentication object that identified the user.
     */
    public function afterIdentify(Event $event, array $user, BaseAuthenticate $auth)
    {
        if ($auth === $this) {
            // The user was identified through this authenticator. Don't set a cookie as a
            // new token was already generated and set in $this->getUserFromCookieData.
            return;
        }

        $cookieTokenComponent = $this->getCookieTokenComponent();

        $cookieTokenComponent->setCookie($user['id']);
    }

    /**
     * Get and validate the cookie data.
     *
     * @return mixed Either false or an array of cookie token data.
     */
    private function getCookieData()
    {
        $cookieComponent = $this->_registry->load('Cookie');
        $data = $cookieComponent->read($this->getConfig('cookie.name'));
        if (!$data || !isset($data['series']) || !isset($data['token'])) {
            // Cookie does not exist or is malformed.
            return false;
        }

        return $data;
    }

    /**
     * Returns a list of all events that this authenticate class will listen to.
     *
     * @see self::$_implementedEvents
     *
     * @return array  List of events this class listens to.
     */
    public function implementedEvents()
    {
        return $this->_implementedEvents;
    }
}
