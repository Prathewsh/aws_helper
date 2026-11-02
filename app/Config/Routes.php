<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

$routes->group('api', ['filter' => ['api-token', 'rate-limit']], static function ($routes) {
    $routes->post('upload', 'Api\UploadController::upload');
});

