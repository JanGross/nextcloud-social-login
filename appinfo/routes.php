<?php
/**
 * Create your routes in here. The name is the lowercase name of the controller
 * without the controller part, the stuff after the hash is the method.
 * e.g. page#index -> OCA\SocialLogin\Controller\PageController->index()
 *
 * The controller class has to be registered in the application.php file since
 * it's instantiated in there
 */
return [
    'routes' => [
        ['name' => 'settings#saveAdmin', 'url' => '/settings/save-admin', 'verb' => 'POST'],
        ['name' => 'login#oauth', 'url' => '/oauth/{provider}', 'verb' => 'GET'],
        ['name' => 'login#openid', 'url' => '/openid/{provider}', 'verb' => 'GET'],
        ['name' => 'login#openid', 'url' => '/openid/{provider}', 'postfix' => '.post', 'verb' => 'POST'],
        ['name' => 'settings#disconnectSocialLogin', 'url' => '/disconnect-social/{login}', 'verb' => 'GET'],
    ]
];
