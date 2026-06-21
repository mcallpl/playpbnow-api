<?php
/**
 * BaseController - Template class for all controllers
 * Provides standard methods for response, error handling, validation, auth
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/ErrorCodes.php';

abstract class BaseController {
    protected $userId = null;
    protected $requestBody = [];
    protected $requestPath = '';
    protected $requestMethod = '';

    public function __construct($requestPath = '', $requestMethod = '', $requestBody = []) {
        $this->requestPath = $requestPath;
        $this->requestMethod = $requestMethod;
        $this->requestBody = $requestBody;
        $this->userId = $GLOBALS['user_id'] ?? null;
    }

    /**
     * Return successful response
     *
     * @param mixed $data Response data
     * @param int $statusCode HTTP status code (default 200)
     */
    protected function response($data = null, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode([
            'status' => 'success',
            'data' => $data,
            'error' => null,
            'timestamp' => date('c')
        ]);
        exit;
    }

    /**
     * Return error response
     *
     * @param string $message Error message
     * @param string $code Error code (from ErrorCodes)
     * @param int $statusCode HTTP status code
     */
    protected function error($message, $code = ErrorCodes::INVALID_INPUT, $statusCode = 400) {
        http_response_code($statusCode);
        echo json_encode([
            'status' => 'error',
            'data' => null,
            'error' => [
                'message' => $message,
                'code' => $code
            ],
            'timestamp' => date('c')
        ]);
        exit;
    }

    /**
     * Return validation error response
     *
     * @param array $errors Validation errors (field => message)
     */
    protected function validationError($errors) {
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'data' => null,
            'error' => [
                'message' => 'Validation failed',
                'code' => ErrorCodes::INVALID_INPUT,
                'details' => $errors
            ],
            'timestamp' => date('c')
        ]);
        exit;
    }

    /**
     * Validate input against rules
     *
     * @param array $rules Validation rules
     * @return array Validated data
     */
    protected function validate($rules) {
        try {
            return Validator::validate($this->requestBody, $rules);
        } catch (ValidationException $e) {
            $this->validationError($e->errors);
        }
    }

    /**
     * Require authentication - returns user_id or throws error
     *
     * @return int User ID
     */
    protected function requireAuth() {
        if (!$this->userId) {
            $this->error(
                ErrorCodes::getMessage(ErrorCodes::UNAUTHORIZED),
                ErrorCodes::UNAUTHORIZED,
                401
            );
        }
        return $this->userId;
    }

    /**
     * Check if user has active subscription
     *
     * @return bool
     */
    protected function hasActiveSubscription() {
        return userHasActiveSubscription($this->userId);
    }

    /**
     * Require active subscription
     */
    protected function requireSubscription() {
        if (!$this->hasActiveSubscription()) {
            $this->error(
                ErrorCodes::getMessage(ErrorCodes::SUBSCRIPTION_REQUIRED),
                ErrorCodes::SUBSCRIPTION_REQUIRED,
                403
            );
        }
    }

    /**
     * Check if user has feature access
     *
     * @param string $feature Feature name
     * @return bool
     */
    protected function hasFeatureAccess($feature) {
        if (!$this->userId) {
            return false;
        }
        return userHasAccess($this->userId, $feature);
    }

    /**
     * Require feature access
     *
     * @param string $feature Feature name
     */
    protected function requireFeature($feature) {
        if (!$this->hasFeatureAccess($feature)) {
            $this->error(
                ErrorCodes::getMessage(ErrorCodes::FORBIDDEN),
                ErrorCodes::FORBIDDEN,
                403
            );
        }
    }

    /**
     * Log request action
     *
     * @param string $action Action name
     * @param array $data Associated data
     */
    protected function logRequest($action, $data = []) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $this->userId,
            'action' => $action,
            'path' => $this->requestPath,
            'method' => $this->requestMethod,
            'data' => $data
        ];

        $logFile = __DIR__ . '/logs/actions.log';
        @file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get request body parameter
     *
     * @param string $key Parameter name
     * @param mixed $default Default value
     * @return mixed
     */
    protected function getParam($key, $default = null) {
        return $this->requestBody[$key] ?? $default;
    }

    /**
     * Get multiple request parameters
     *
     * @param array $keys Parameter names
     * @return array
     */
    protected function getParams($keys) {
        $params = [];
        foreach ($keys as $key) {
            $params[$key] = $this->requestBody[$key] ?? null;
        }
        return $params;
    }

    /**
     * Paginate query results
     *
     * @param int $page Page number (1-indexed)
     * @param int $limit Items per page
     * @return array [offset, limit]
     */
    protected function getPagination($page = 1, $limit = 20) {
        $page = max(1, (int)($this->getParam('page', $page)));
        $limit = max(1, min(100, (int)($this->getParam('limit', $limit))));
        $offset = ($page - 1) * $limit;

        return ['offset' => $offset, 'limit' => $limit, 'page' => $page];
    }

    /**
     * Return paginated response
     *
     * @param array $items Items
     * @param int $total Total count
     * @param int $page Current page
     * @param int $limit Items per page
     */
    protected function paginated($items, $total, $page = 1, $limit = 20) {
        $this->response([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    /**
     * Parse query string into array
     *
     * @param string $query Query string
     * @return array
     */
    protected function parseQuery($query) {
        $params = [];
        parse_str($query, $params);
        return $params;
    }
}
?>
