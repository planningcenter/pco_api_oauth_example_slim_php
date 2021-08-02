<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use DI\Container;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container();
$container->set("OAUTH_APP_ID", getenv("OAUTH_APP_ID"));
$container->set("OAUTH_SECRET", getenv("OAUTH_SECRET"));
$container->set("SCOPE", "people services");
$container->set("API_URL", "https://api.planningcenteronline.com");
$container->set("TOKEN_EXPIRATION_PADDING", 300); /* go ahead and refresh a token if it's within this many seconds of expiring */
AppFactory::setContainer($container);
$app = AppFactory::create();

$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("<h1>Hello world!</h1>" . $this->get("SCOPE"));
    return $response;
});

$app->run();
