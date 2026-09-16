<?php

declare(strict_types=1);

// Basic PSR-4 style autoloader for App\ namespace
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use App\Router;
use App\Controllers\DashboardController;
use App\Controllers\SystemController;
use App\Controllers\BotController;
use App\Controllers\LogController;

$router = new Router();

// Dashboard
$router->get('/', [DashboardController::class, 'index']);

// Systems
$router->get('/systems', [SystemController::class, 'index']);
$router->post('/systems', [SystemController::class, 'store']);
$router->post('/systems/{id}/delete', [SystemController::class, 'delete']);

// Bot Profiles
$router->get('/bots', [BotController::class, 'index']);
$router->get('/bots/create', [BotController::class, 'create']);
$router->post('/bots', [BotController::class, 'store']);
$router->get('/bots/{id}/edit', [BotController::class, 'edit']);
$router->post('/bots/{id}', [BotController::class, 'update']);
$router->post('/bots/{id}/delete', [BotController::class, 'delete']);
$router->get('/bots/{id}/embed', [BotController::class, 'embed']);

// Logs & Transcripts
$router->get('/logs', [LogController::class, 'index']);
$router->get('/logs/{id}/transcript', [LogController::class, 'transcript']);

// Dispatch request
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

$router->dispatch($method, $uri);
