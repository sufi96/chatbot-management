<?php

namespace App;

class Router {
    private array $routes = [];

    public function get(string $path, array $handler): void {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, array $handler): void {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void {
        $path = parse_url($uri, PHP_URL_PATH);
        
        // Match exact route
        if (isset($this->routes[$method][$path])) {
            [$controllerClass, $action] = $this->routes[$method][$path];
            $controller = new $controllerClass();
            $controller->$action();
            return;
        }

        // Match parameterized route, e.g. /bots/{id}/edit
        foreach ($this->routes[$method] ?? [] as $routePattern => $handler) {
            $regex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $routePattern);
            $regex = '#^' . $regex . '$#';

            if (preg_match($regex, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                [$controllerClass, $action] = $handler;
                $controller = new $controllerClass();
                $controller->$action($params);
                return;
            }
        }

        http_response_code(404);
        echo "<h1>404 Not Found</h1><p>The requested route <code>" . htmlspecialchars($path) . "</code> does not exist.</p>";
    }
}
