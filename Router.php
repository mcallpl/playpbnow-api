<?php
/**
 * Router - Central request dispatcher using FastRoute
 * Parses incoming requests, matches against routes, executes middleware and controller
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/ErrorCodes.php';
require_once __DIR__ . '/Middleware.php';

// Simple FastRoute fallback if not installed via Composer
if (!class_exists('FastRoute\RouteCollector')) {
    require_once __DIR__ . '/FastRouteSimple.php';
}

class Router {
    private $routes = [];
    private $middleware = [];
    private $basePath = '/api';
    private static $instance = null;

    public function __construct($basePath = '/api') {
        $this->basePath = $basePath;
    }

    /**
     * Get singleton instance
     */
    public static function getInstance($basePath = '/api') {
        if (self::$instance === null) {
            self::$instance = new self($basePath);
        }
        return self::$instance;
    }

    /**
     * Add a route
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $path Route path (e.g., '/users/{id}')
     * @param string $controller Controller class name
     * @param string $action Action method name
     * @param array $middlewares Optional middleware for this route
     */
    public function addRoute($method, $path, $controller, $action, $middlewares = []) {
        $key = "{$method} {$path}";
        $this->routes[$key] = [
            'method' => $method,
            'path' => $path,
            'controller' => $controller,
            'action' => $action,
            'middleware' => array_merge($this->middleware, $middlewares)
        ];
    }

    /**
     * Add GET route
     */
    public function get($path, $controller, $action, $middlewares = []) {
        $this->addRoute('GET', $path, $controller, $action, $middlewares);
    }

    /**
     * Add POST route
     */
    public function post($path, $controller, $action, $middlewares = []) {
        $this->addRoute('POST', $path, $controller, $action, $middlewares);
    }

    /**
     * Add PUT route
     */
    public function put($path, $controller, $action, $middlewares = []) {
        $this->addRoute('PUT', $path, $controller, $action, $middlewares);
    }

    /**
     * Add DELETE route
     */
    public function delete($path, $controller, $action, $middlewares = []) {
        $this->addRoute('DELETE', $path, $controller, $action, $middlewares);
    }

    /**
     * Add PATCH route
     */
    public function patch($path, $controller, $action, $middlewares = []) {
        $this->addRoute('PATCH', $path, $controller, $action, $middlewares);
    }

    /**
     * Add group middleware (applied to all routes added after this call)
     */
    public function middleware($middlewares) {
        $this->middleware = array_merge($this->middleware, (array)$middlewares);
        return $this;
    }

    /**
     * Dispatch request to appropriate controller
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @param array $body Request body (JSON decoded)
     */
    public function dispatch($method, $path, $body = []) {
        // Remove leading /api prefix if present
        if (strpos($path, $this->basePath) === 0) {
            $path = substr($path, strlen($this->basePath));
        }

        // Ensure path starts with /
        if (strpos($path, '/') !== 0) {
            $path = '/' . $path;
        }

        // Find matching route
        $match = $this->matchRoute($method, $path);

        if (!$match) {
            return $this->notFound($method, $path);
        }

        // Extract parameters from path
        $params = $this->extractParams($match['path'], $path);

        // Merge params with body
        $requestBody = array_merge($body, $params);

        // Execute middleware and controller
        try {
            return $this->executeRoute($match, $path, $method, $requestBody);
        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Run router with current request
     */
    public function run() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Get request body
        $body = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $input = file_get_contents('php://input');
            $body = json_decode($input, true) ?? [];
        }

        $this->dispatch($method, $path, $body);
    }

    /**
     * Match incoming request to registered route
     */
    private function matchRoute($method, $path) {
        // Exact match first
        $key = "{$method} {$path}";
        if (isset($this->routes[$key])) {
            return $this->routes[$key];
        }

        // Pattern match with parameters
        foreach ($this->routes as $routeKey => $route) {
            [$routeMethod, $routePath] = explode(' ', $routeKey, 2);

            if ($routeMethod !== $method) {
                continue;
            }

            if ($this->pathMatches($routePath, $path)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Check if route pattern matches path
     * e.g., '/users/{id}' matches '/users/123'
     */
    private function pathMatches($pattern, $path) {
        // Convert pattern to regex
        $regex = preg_replace('/\{([^}]+)\}/', '([^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        return (bool)preg_match($regex, $path);
    }

    /**
     * Extract parameters from path
     * e.g., from '/users/123' with pattern '/users/{id}' => ['id' => '123']
     */
    private function extractParams($pattern, $path) {
        $params = [];

        // Find all parameter names in pattern
        preg_match_all('/\{([^}]+)\}/', $pattern, $matches);
        $paramNames = $matches[1] ?? [];

        // Find all values in path
        $regex = preg_replace('/\{[^}]+\}/', '([^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            $values = array_slice($matches, 1);
            foreach ($paramNames as $i => $name) {
                $params[$name] = $values[$i] ?? null;
            }
        }

        return $params;
    }

    /**
     * Execute route with middleware
     */
    private function executeRoute($route, $path, $method, $body) {
        $controllerClass = $route['controller'];
        $actionMethod = $route['action'];
        $middlewares = $route['middleware'] ?? [];

        // Load controller if needed
        if (!class_exists($controllerClass)) {
            $controllerFile = __DIR__ . '/controllers/' . $controllerClass . '.php';
            if (!file_exists($controllerFile)) {
                throw new Exception("Controller not found: {$controllerClass}");
            }
            require_once $controllerFile;
        }

        // Create controller instance
        $controller = new $controllerClass($path, $method, $body);

        // Execute middleware chain
        $next = function() use ($controller, $actionMethod) {
            if (!method_exists($controller, $actionMethod)) {
                throw new Exception("Action not found: {$actionMethod}");
            }
            return $controller->$actionMethod();
        };

        if (empty($middlewares)) {
            return $next();
        }

        return Middleware::executeStack($middlewares, $next);
    }

    /**
     * Handle not found route
     */
    private function notFound($method, $path) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'data' => null,
            'error' => [
                'message' => 'Route not found',
                'code' => ErrorCodes::NOT_FOUND
            ],
            'timestamp' => date('c')
        ]);
        exit;
    }

    /**
     * Handle exceptions
     */
    private function handleException($e) {
        error_log("Router exception: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());

        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'data' => null,
            'error' => [
                'message' => 'Internal server error',
                'code' => ErrorCodes::INTERNAL_ERROR
            ],
            'timestamp' => date('c')
        ]);
        exit;
    }

    /**
     * Get all registered routes (for debugging)
     */
    public function getRoutes() {
        return $this->routes;
    }
}
?>
