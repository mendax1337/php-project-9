<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;

$app = AppFactory::create();

$renderer = new PhpRenderer(__DIR__ . '/../templates');

$app->get('/', function ($request, $response) use ($renderer) {
    return $renderer->render($response, 'main.phtml');
});

$app->run();
