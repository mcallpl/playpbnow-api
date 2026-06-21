<?php
/**
 * Middleware - Middleware stack for request processing
 * Executes middleware chain before controller action
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/ErrorCodes.php';

class Middleware {
    /**
     * Execute middleware stack
     *
     * @param array $middlewares List of middleware class names to execute
     * @param callable $next Callback to execute after middleware chain
     */
    public static function executeStack($middlewares, $next) {
        // Build middleware chain
        $chain = $next;

        // Reverse to execute in correct order (last middleware wraps first)
        foreach (array_reverse($middlewares) as $middlewareName) {
            $middleware = new $middlewareName();
            $chain = function() use ($middleware, $chain) {
                return $middleware->handle($chain);
            };
        }

        // Execute chain
        return $chain();
    }
}

/**
 * AuthMiddleware - Verify authentication token and set user context
 */
class AuthMiddleware {
    public function handle($next) {
        $token = $this->getToken();

        if (empty($token)) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'message' => ErrorCodes::getMessage(ErrorCodes::UNAUTHORIZED),
                    'code' => ErrorCodes::UNAUTHORIZED
                ],
                'timestamp' => date('c')
            ]);
            exit;
        }

        // Verify token and extract user_id
        // TODO: Implement token validation (JWT or custom token scheme)
        $userId = $this->verifyToken($token);

        if (!$userId) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'message' => ErrorCodes::getMessage(ErrorCodes::TOKEN_INVALID),
                    'code' => ErrorCodes::TOKEN_INVALID
                ],
                'timestamp' => date('c')
            ]);
            exit;
        }

        // Set global user context
        $GLOBALS['user_id'] = $userId;
        $GLOBALS['auth_token'] = $token;

        // Continue chain
        return $next();
    }

    private function getToken() {
        // Check Authorization header
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $parts = explode(' ', $headers['Authorization']);
            if (count($parts) === 2 && $parts[0] === 'Bearer') {
                return $parts[1];
            }
        }

        // Check X-Auth-Token header
        if (isset($headers['X-Auth-Token'])) {
            return $headers['X-Auth-Token'];
        }

        // Check query parameter (fallback)
        if (isset($_GET['token'])) {
            return $_GET['token'];
        }

        return null;
    }

    private function verifyToken($token) {
        // TODO: Implement actual token verification (JWT, database lookup, etc.)
        // For now, return a placeholder
        // This should validate token format, signature, expiry, and return user_id
        return null;
    }
}

/**
 * CORSMiddleware - Set CORS headers
 */
class CORSMiddleware {
    public function handle($next) {
        // Set CORS headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
        header('Content-Type: application/json; charset=utf-8');

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        return $next();
    }
}

/**
 * LoggingMiddleware - Log all requests to file
 */
class LoggingMiddleware {
    public function handle($next) {
        $startTime = microtime(true);

        // Capture request details
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_SERVER['REQUEST_URI'];
        $ip = $this->getClientIp();
        $userId = $GLOBALS['user_id'] ?? null;

        // Execute request
        $result = $next();

        // Log request
        $duration = microtime(true) - $startTime;
        $statusCode = http_response_code();
        $timestamp = date('Y-m-d H:i:s');

        $logEntry = json_encode([
            'timestamp' => $timestamp,
            'method' => $method,
            'path' => $path,
            'ip' => $ip,
            'user_id' => $userId,
            'status_code' => $statusCode,
            'duration_ms' => round($duration * 1000, 2)
        ]) . PHP_EOL;

        $logFile = __DIR__ . '/logs/requests.log';
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

        return $result;
    }

    private function getClientIp() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}

/**
 * RateLimitMiddleware - Rate limiting (100 requests per minute per IP)
 */
class RateLimitMiddleware {
    private const MAX_REQUESTS = 100;
    private const TIME_WINDOW = 60; // seconds

    public function handle($next) {
        $ip = $this->getClientIp();
        $key = 'rate_limit:' . $ip;

        // Try to use Redis if available
        if (function_exists('redis_connect')) {
            return $this->handleWithRedis($key, $next);
        }

        // Fall back to file-based counter
        return $this->handleWithFiles($key, $next, $ip);
    }

    private function handleWithRedis($key, $next) {
        try {
            // TODO: Implement Redis-based rate limiting
            return $next();
        } catch (Exception $e) {
            error_log("Redis error: " . $e->getMessage());
            return $this->handleWithFiles($key, $next);
        }
    }

    private function handleWithFiles($key, $next, $ip) {
        $cacheDir = __DIR__ . '/logs/rate_limit';
        @mkdir($cacheDir, 0755, true);

        $fileName = $cacheDir . '/' . md5($ip);
        $now = time();

        // Read current counter
        $data = [];
        if (file_exists($fileName)) {
            $data = @json_decode(@file_get_contents($fileName), true) ?? [];
        }

        // Clean old entries (older than time window)
        $data['requests'] = array_filter($data['requests'] ?? [], function($timestamp) use ($now) {
            return ($now - $timestamp) < self::TIME_WINDOW;
        });

        // Check rate limit
        if (count($data['requests']) >= self::MAX_REQUESTS) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'message' => ErrorCodes::getMessage(ErrorCodes::RATE_LIMITED),
                    'code' => ErrorCodes::RATE_LIMITED
                ],
                'timestamp' => date('c')
            ]);
            exit;
        }

        // Add current request
        $data['requests'][] = $now;
        @file_put_contents($fileName, json_encode($data), LOCK_EX);

        return $next();
    }

    private function getClientIp() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
?>
