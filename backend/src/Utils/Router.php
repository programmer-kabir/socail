<?php
declare(strict_types=1);

require_once __DIR__ . '/Response.php';

/**
 * SocialFlow - Lightweight Modular REST API Router
 */

class Router
{
    private array $routes = [];
    private array $globalMiddlewares = [];

    public function use(callable $middleware): void
    {
        $this->globalMiddlewares[] = $middleware;
    }

    public function get(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middlewares);
    }

    public function delete(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middlewares);
    }

    public function options(string $path, callable|array $handler): void
    {
        $this->addRoute('OPTIONS', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->routes[] = [
            'method'      => $method,
            'path'        => $path,
            'handler'     => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function dispatch(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Normalize trailing slashes
        if ($requestUri !== '/' && str_ends_with($requestUri, '/')) {
            $requestUri = rtrim($requestUri, '/');
        }

        // Handle CORS Preflight request
        if ($requestMethod === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Parse JSON body if present
        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $rawInput = file_get_contents('php://input');
            if (!empty($rawInput)) {
                $decoded = json_decode($rawInput, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $body = $decoded;
                }
            }
        } else {
            $body = $_POST;
        }

        $request = [
            'method' => $requestMethod,
            'uri'    => $requestUri,
            'query'  => $_GET,
            'body'   => $body,
            'params' => [],
            'files'  => $_FILES,
            'user'   => null,
            'workspace' => null,
        ];

        // Execute global middlewares
        foreach ($this->globalMiddlewares as $middleware) {
            $request = $middleware($request);
            if ($request === false) {
                return; // Middleware handled response
            }
        }

        // Match route
        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $requestUri, $matches)) {
                $params = [];
                foreach ($matches as $key => $val) {
                    if (is_string($key)) {
                        $params[$key] = $val;
                    }
                }
                $request['params'] = $params;

                // Execute route-specific middlewares
                foreach ($route['middlewares'] as $routeMiddleware) {
                    $request = $routeMiddleware($request);
                    if ($request === false) {
                        return;
                    }
                }

                // Execute handler
                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $method] = $handler;
                    $controllerInstance = new $class();
                    $controllerInstance->$method($request);
                } else {
                    $handler($request);
                }
                return;
            }
        }

        // 404 Not Found
        Response::error("Route {$requestMethod} {$requestUri} not found.", 404);
    }
}
