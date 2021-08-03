<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use DI\Container;
use SlimSession\Helper as SessionHelper;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container();
$container->set("OAUTH_APP_ID", getenv("OAUTH_APP_ID"));
$container->set("OAUTH_SECRET", getenv("OAUTH_SECRET"));
$container->set("SCOPE", "people services");
$container->set("API_URL", "https://api.planningcenteronline.com");
$container->set("TOKEN_EXPIRATION_PADDING", 300); /* go ahead and refresh a token if it's within this many seconds of expiring */
$container->set("session", function () {
    return new SessionHelper();
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

$app->get('/', function (Request $request, Response $response, $args) {
    $session = $this->get("session");
    // If we have a token to make requests
    if ($session->exists("token")) {
        // Check if we need to refresh it
        // Fetch some people from the Planning Center API
    } else {
        // Otherwise, show a link to /auth to login with Planning Center
    }

    $response->getBody()->write("<h1>Hello world!</h1>" . $this->get("SCOPE"));
    return $response;
});

$app->get("/auth", function (Request $request, Response $response, $args) {
    // Build the authorization URL and redirect to it
});

$app->get("/auth/complete", function (Request $request, Response $response, $args) {
    // We successfully authenticated with Planning Center and have been redirected back with a code
    // Use the code to fetch our access token
    // Set the token in our session
    // Redirect home
});

$app->get("/auth/logout", function (Request $request, Response $response, $args) {
    // Revoke our authentication
    // Clear our token out of the session
    // Redirect home
});

$app->run();
