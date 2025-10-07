<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use DI\Container;
use SlimSession\Helper as SessionHelper;
use League\OAuth2\Client\Provider\GenericProvider as OAuthProvider;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container();
$container->set("apiUrl", getenv("API_URL") ?: "https://api.planningcenteronline.com");
$container->set("tokenExpirationPadding", 300); // go ahead and refresh a token if it's within this many seconds of expiring
$container->set("session", function () {
    return new SessionHelper();
});
$container->set("oauth", function() use($container) {
    return new OAuthProvider([
        "clientId" => getenv("OAUTH_APP_ID"),
        "clientSecret" => getenv("OAUTH_SECRET"),
        "redirectUri" => "http://localhost:6001/auth/complete",
        "scopeSeparator" => " ",
        "scopes" => ["openid", "people"],
        "urlAccessToken" => "{$container->get("apiUrl")}/oauth/token",
        "urlAuthorize" => "{$container->get("apiUrl")}/oauth/authorize",
        "urlResourceOwnerDetails" => "{$container->get("apiUrl")}/oauth/userinfo",
        "pkceMethod" => \League\OAuth2\Client\Provider\GenericProvider::PKCE_METHOD_S256
    ]);
});
AppFactory::setContainer($container);
$app = AppFactory::create();
$app->add(
    new \Slim\Middleware\Session([
        "name" => "pco_api_session",
        "autorefresh" => true,
        "lifetime" => "4 hours",
    ])
);
$twig = Twig::create("../templates", ["cache" => false]);
$app->add(TwigMiddleware::create($app, $twig));

$app->get('/', function (Request $request, Response $response, $args) {
    $session = $this->get("session");
    $view = Twig::fromRequest($request);

    // If we have a token, redirect to /people
    if ($session->exists("token")) {
        return $response
            ->withHeader("Location", "/people")
            ->withStatus(302);
    } else {
        // Otherwise, show a link to /auth to login with Planning Center
        return $view->render($response, "login.html");
    }
});

$app->get('/people', function (Request $request, Response $response, $args) {
    $oauth = $this->get("oauth");
    $apiUrl = $this->get("apiUrl");
    $session = $this->get("session");
    $view = Twig::fromRequest($request);

    // If we don't have a token, redirect to auth
    if (!$session->exists("token")) {
        return $response
            ->withHeader("Location", "/auth")
            ->withStatus(302);
    }

    $token = $session->token;

    // Refresh token if needed
    if (
        $token->getExpires() &&
        ($token->getExpires() < time() + $this->get("tokenExpirationPadding")) &&
        $token->getRefreshToken()
    ) {
        $newToken = $oauth->getAccessToken("refresh_token", [
            "refresh_token" => $token->getRefreshToken()
        ]);
        $session->token = $newToken;
        $token = $newToken;
    }

    // Fetch some people from the Planning Center API
    $peopleResponse = $oauth->getAuthenticatedRequest("GET", "{$apiUrl}/people/v2/people", $token);
    $parsedResponse = $oauth->getParsedResponse($peopleResponse);
    $people = $parsedResponse["data"];

    return $view->render(
        $response,
        "people.html",
        [
            "loggedIn" => isset($session->token),
            "people" => $people,
            "parsedResponse" => $parsedResponse
        ],
    );
});

$app->get("/auth", function (Request $request, Response $response, $args) {
    // Build the authorization URL and redirect to it
    $oauth = $this->get("oauth");
    $session = $this->get("session");

    $authorizationUrl = $oauth->getAuthorizationUrl([
        'prompt' => 'select_account' // to allow user account selection or "login" to force re-authentication
    ]);

    $session->set("code_verifier", $oauth->getPkceCode());

    return $response
        ->withHeader("Location", $authorizationUrl)
        ->withStatus(302);
});

$app->get("/auth/complete", function (Request $request, Response $response, $args) {
    // We successfully authenticated with Planning Center and have been redirected back with a code
    $code = $request->getQueryParams()["code"];
    // Use the code to fetch our access token
    $oauth = $this->get("oauth");

    // Restore the PKCE code for the token exchange
    $session = $this->get("session");
    if ($session->exists("code_verifier")) {
        $oauth->setPkceCode($session->get("code_verifier"));
    }

    $token = $oauth->getAccessToken("authorization_code", ["code" => $code]);

    $session->delete("code_verifier");

    // Set the token in our session
    $session->set("token", $token);

    // Check if we have an id_token
    $tokenValues = $token->getValues();
    if (isset($tokenValues['id_token'])) {
        # Basic JWT decoding without signature verification for demo purposes only.
        #
        # NOTE: In production, you should verify the JWT signature using the JWK from the
        # JWKS endpoint ({API_URL}/oauth/discovery/keys).
        #
        # This verification will happen automatically if using an authentication
        # package like `php-openid-client` or `oidc-client`. Otherwise a package like `php-jwt`
        # can be used to manually build the public key from the JWK hash to pass into
        # `JWT::decode`.
        $idTokenParts = explode('.', $tokenValues['id_token']);
        if (count($idTokenParts) === 3) {
            $payload = json_decode(base64_decode($idTokenParts[1]), true);

            // Fetch user info from /oauth/userinfo endpoint
            $apiUrl = $this->get("apiUrl");
            $userInfoRequest = $oauth->getAuthenticatedRequest("GET", "{$apiUrl}/oauth/userinfo", $token);
            $userInfoResponse = $oauth->getParsedResponse($userInfoRequest);

            // Set current_user in session with name from userinfo and claims from id_token
            $session->set("current_user", [
                "name" => $userInfoResponse["name"],
                "claims" => $payload
            ]);
        }
    }

    // Redirect home
    return $response
        ->withHeader("Location", "/")
        ->withStatus(302);
});

$app->get("/profile", function (Request $request, Response $response, $args) {
    $session = $this->get("session");
    $view = Twig::fromRequest($request);

    // Check if user is logged in and has user info
    if (!$session->exists("token") || !$session->exists("current_user")) {
        return $response
            ->withHeader("Location", "/auth")
            ->withStatus(302);
    }

    $currentUser = $session->get("current_user");

    $apiUrl = $this->get("apiUrl");
    $oauth = $this->get("oauth");
    $token = $session->token;
    $userInfoRequest = $oauth->getAuthenticatedRequest("GET", "{$apiUrl}/oauth/userinfo", $token);
    $userInfoResponse = $oauth->getParsedResponse($userInfoRequest);

    return $view->render(
        $response,
        "profile.html",
        [
            "loggedIn" => true,
            "currentUser" => $currentUser,
            "idTokenClaims" => $currentUser["claims"],
            "userInfoClaims" => $userInfoResponse
        ]
    );
});

$app->get("/auth/logout", function (Request $request, Response $response, $args) {
    $oauth = $this->get("oauth");
    $apiUrl = $this->get('apiUrl');
    $session = $this->get("session");
    $token = $session->token;

    // Revoke our authentication
    $revokeRequest = $oauth->getAuthenticatedRequest(
        "POST",
        "{$apiUrl}/oauth/revoke",
        $token,
        ["token" => $token],
    );

    // Clear our token and user info out of the session
    $session->delete("token");
    $session->delete("current_user");

    // Redirect home
    return $response
        ->withHeader("Location", "/")
        ->withStatus(302);
});

$app->run();
