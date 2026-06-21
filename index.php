<?php
/**
 * API Index - Central entry point for all API requests
 * Routes all incoming requests through Router with middleware stack
 */

// Set error handling
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to client
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// Ensure logs directory exists
@mkdir(__DIR__ . '/logs', 0755, true);

// Set response content type
header('Content-Type: application/json; charset=utf-8');

// Set timezone
date_default_timezone_set('America/Los_Angeles');

try {
    // Load required files
    require_once __DIR__ . '/Router.php';
    require_once __DIR__ . '/Middleware.php';
    require_once __DIR__ . '/db_config.php';

    // Load routes
    require_once __DIR__ . '/routes.php';

    // Get HTTP method and path
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    // Get request body
    $body = [];
    if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $body = json_decode($input, true) ?? [];
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'data' => null,
                    'error' => [
                        'message' => 'Invalid JSON in request body',
                        'code' => 'INVALID_JSON'
                    ],
                    'timestamp' => date('c')
                ]);
                exit;
            }
        }
    }

    // Dispatch request through router with global middleware
    $router->middleware(['CORSMiddleware', 'LoggingMiddleware', 'RateLimitMiddleware']);
    $router->dispatch($method, $path, $body);

} catch (Exception $e) {
    error_log("Unhandled exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'data' => null,
        'error' => [
            'message' => 'Internal server error',
            'code' => 'INTERNAL_ERROR'
        ],
        'timestamp' => date('c')
    ]);
    exit;
}
?>
