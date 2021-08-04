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
$container->set("apiUrl", "https://api.planningcenteronline.com");
$container->set("tokenExpirationPadding", 300); // go ahead and refresh a token if it's within this many seconds of expiring
$container->set("session", function () {
    return new SessionHelper();
});
$container->set("oauth", function() use($container) {
    return new OAuthProvider([
        "clientId" => getenv("OAUTH_APP_ID"),
        "clientSecret" => getenv("OAUTH_SECRET"),
        "redirectUri" => "http://localhost:8000/auth/complete",
        "scopeSeparator" => " ",
        "scopes" => ["people"],
        "urlAccessToken" => "{$container->get("apiUrl")}/oauth/token",
        "urlAuthorize" => "{$container->get("apiUrl")}/oauth/authorize",
        "urlResourceOwnerDetails" => "{$container->get("apiUrl")}/me"
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
    $oauth = $this->get("oauth");
    $apiUrl = $this->get("apiUrl");
    $session = $this->get("session");
    $view = Twig::fromRequest($request);

    // If we have a token to make requests
    if ($session->exists("token")) {
        $token = $session->token;

        // Refresh token if needed
        if (
            $token->getExpires() &&
            ($token->getExpires() < time() + $this->get("tokenExpirationPadding")) &&
            token->getRefreshToken()
        ) {
            $newToken = $oauth->getAccessToken("refresh_token", [
                "refresh_token" => $token->getRefreshToken()
            ]);
            $session->token = $newToken;
        }

        // Fetch some people from the Planning Center API
        $peopleResponse = $oauth->getAuthenticatedRequest("GET", "{$apiUrl}/people/v2/people", $token);
        $parsedResponse = $oauth->getParsedResponse($peopleResponse);
        $people = $parsedResponse["data"];

        return $view->render(
            $response,
            "index.html",
            [
                "loggedIn" => isset($session->token),
                "people" => $people,
                "parsedResponse" => $parsedResponse
            ],
        );
    } else {
        // Otherwise, show a link to /auth to login with Planning Center
        return $view->render($response, "login.html");
    }
});

$app->get("/auth", function (Request $request, Response $response, $args) {
    // Build the authorization URL and redirect to it
    $oauth = $this->get("oauth");
    $authorizationUrl = $oauth->getAuthorizationUrl();

    return $response
        ->withHeader("Location", $authorizationUrl)
        ->withStatus(302);
});

$app->get("/auth/complete", function (Request $request, Response $response, $args) {
    // We successfully authenticated with Planning Center and have been redirected back with a code
    $code = $request->getQueryParams()["code"];
    // Use the code to fetch our access token
    $oauth = $this->get("oauth");
    $token = $oauth->getAccessToken("authorization_code", ["code" => $code]);
    // Set the token in our session
    $this->get("session")->set("token", $token);

    // Redirect home
    return $response
        ->withHeader("Location", "/")
        ->withStatus(302);
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

    // Clear our token out of the session
    $session->delete("token");

    // Redirect home
    return $response
        ->withHeader("Location", "/")
        ->withStatus(302);
});

$app->run();
