# PlayPBNow API Foundation (Phase 1)

## Overview
This is the foundation layer for the PlayPBNow REST API, providing a clean, DRY architecture to replace the 140+ endpoint files and 27K-line admin_api.php.

## Architecture Components

### 1. **Router.php** - Central Request Dispatcher
- Parses incoming HTTP requests by method and path
- Matches requests against registered routes
- Extracts path parameters (e.g., `/users/{id}` → `['id' => '123']`)
- Executes middleware stack before controller action
- Returns standardized responses

**Key Methods:**
```php
$router->get('/path', 'ControllerName', 'methodName')
$router->post('/path', 'ControllerName', 'methodName')
$router->put('/path', 'ControllerName', 'methodName')
$router->delete('/path', 'ControllerName', 'methodName')
$router->dispatch($method, $path, $body)
```

### 2. **BaseController.php** - Template for All Controllers
All controller classes extend BaseController, providing standard methods:

```php
protected function response($data, $statusCode = 200)  // Success response
protected function error($message, $code, $statusCode) // Error response
protected function validate($rules)                     // Input validation
protected function requireAuth()                        // Check auth, throw if missing
protected function requireSubscription()                // Check subscription
protected function logRequest($action, $data)           // Log action
protected function paginated($items, $total, $page, $limit) // Pagination helper
```

### 3. **Validator.php** - Input Validation Layer
Validates user input against rules. Supports:
- `required` - Field must be present and non-empty
- `email` - Must be valid email
- `phone` - Must be valid phone (10-11 digits)
- `integer` - Must be integer
- `numeric` - Must be numeric
- `string` - Must be string
- `array` - Must be array
- `min:N` - Minimum length/value
- `max:N` - Maximum length/value
- `regex:pattern` - Must match regex
- `in:val1,val2` - Must be one of values
- `not_in:val1,val2` - Must not be one of values

**Usage:**
```php
try {
    $data = Validator::validate($_POST, [
        'email' => 'required|email',
        'phone' => 'required|phone',
        'age' => 'required|integer|min:18|max:120',
        'name' => 'required|string|min:2|max:100'
    ]);
    // $data contains validated values
} catch (ValidationException $e) {
    // $e->errors contains field-level errors
}
```

### 4. **Middleware.php** - Request Processing Pipeline
Executes before controller action. Middleware stack:

**Built-in Middleware:**
- `CORSMiddleware` - Sets CORS headers, handles OPTIONS
- `LoggingMiddleware` - Logs all requests to `logs/requests.log`
- `RateLimitMiddleware` - 100 requests/minute per IP
- `AuthMiddleware` - Verifies auth token, sets `$GLOBALS['user_id']`

**Middleware Execution:**
```php
Middleware::executeStack(['AuthMiddleware', 'LoggingMiddleware'], $next)
```

### 5. **ErrorCodes.php** - Standardized Error Constants
Central error codes used throughout the API:

```php
ErrorCodes::INVALID_INPUT           // Input validation failed
ErrorCodes::UNAUTHORIZED            // Auth required but missing
ErrorCodes::FORBIDDEN               // Access denied
ErrorCodes::NOT_FOUND               // Resource not found
ErrorCodes::SUBSCRIPTION_REQUIRED   // Premium feature
ErrorCodes::RATE_LIMITED            // Too many requests
ErrorCodes::DATABASE_ERROR          // Database failure
ErrorCodes::INTERNAL_ERROR          // Server error
```

Each code has a human-readable message via `ErrorCodes::getMessage($code)`.

### 6. **routes.php** - Route Definitions
Central registry of all API endpoints. Format:
```php
$router->post('/auth/login', 'AuthController', 'login');
$router->get('/users/{id}', 'UserController', 'getUser', ['AuthMiddleware']);
```

Currently defines **52 routes** across 10 controllers:
- Auth (8 routes)
- User (5 routes)
- Player (6 routes)
- Pool (3 routes)
- Match (7 routes)
- Group (7 routes)
- Invite (6 routes)
- SMS (4 routes)
- Subscription (4 routes)
- Health (2 routes)

## Response Format

All API responses follow this format:

**Success (2xx):**
```json
{
  "status": "success",
  "data": { /* actual response data */ },
  "error": null,
  "timestamp": "2024-06-21T14:30:45-07:00"
}
```

**Error (4xx/5xx):**
```json
{
  "status": "error",
  "data": null,
  "error": {
    "message": "Human-readable error message",
    "code": "ERROR_CODE",
    "details": { /* optional field-level errors for validation */ }
  },
  "timestamp": "2024-06-21T14:30:45-07:00"
}
```

## Directory Structure

```
playpbnow-api/
├── index.php                    # Entry point for all requests
├── Router.php                   # Request dispatcher
├── BaseController.php           # Controller template
├── Validator.php               # Input validation
├── Middleware.php              # Middleware stack
├── ErrorCodes.php              # Error constants
├── routes.php                  # Route definitions
├── FastRouteSimple.php         # FastRoute fallback
├── controllers/                # Controller classes
│   ├── AuthController.php
│   ├── UserController.php
│   ├── PlayerController.php
│   ├── PoolController.php
│   ├── MatchController.php
│   ├── GroupController.php
│   ├── InviteController.php
│   ├── SMSController.php
│   ├── SubscriptionController.php
│   └── HealthController.php
├── logs/                       # Log directory
│   ├── requests.log            # HTTP request log
│   ├── actions.log             # User action log
│   ├── php_errors.log          # PHP errors
│   └── rate_limit/             # Rate limiting cache
└── db_config.php              # Database configuration

```

## Setup Instructions

1. **Create logs directory:**
   ```bash
   mkdir -p playpbnow-api/logs/rate_limit
   chmod 755 playpbnow-api/logs
   ```

2. **Configure .htaccess** (for Apache):
   ```apache
   <IfModule mod_rewrite.c>
     RewriteEngine On
     RewriteCond %{REQUEST_FILENAME} !-f
     RewriteCond %{REQUEST_FILENAME} !-d
     RewriteRule ^ index.php [QSA,L]
   </IfModule>
   ```

3. **Configure nginx** (if using nginx):
   ```nginx
   location /api/ {
     try_files $uri $uri/ /index.php?$query_string;
   }
   ```

## Creating a New Controller

All controllers extend BaseController:

```php
<?php
require_once __DIR__ . '/../BaseController.php';

class MyController extends BaseController {
    public function list() {
        // Require auth
        $userId = $this->requireAuth();
        
        // Validate input
        $data = $this->validate([
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:100'
        ]);
        
        // Get paginated results
        $result = dbGetAll("SELECT * FROM table LIMIT ?, ?", 
            [$data['offset'], $data['limit']]);
        
        // Return success response
        $this->response($result);
    }
    
    public function create() {
        $userId = $this->requireAuth();
        
        // Validate input
        $data = $this->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email'
        ]);
        
        // Create record
        $id = dbInsert(
            "INSERT INTO table (user_id, name, email) VALUES (?, ?, ?)",
            [$userId, $data['name'], $data['email']]
        );
        
        if (!$id) {
            $this->error('Failed to create record', ErrorCodes::INSERT_ERROR, 500);
        }
        
        $this->response(['id' => $id], 201);
    }
}
?>
```

## Adding Routes

```php
// In routes.php
$router->post('/my-feature', 'MyController', 'create');
$router->get('/my-feature/{id}', 'MyController', 'get', ['AuthMiddleware']);
$router->put('/my-feature/{id}', 'MyController', 'update', ['AuthMiddleware']);
$router->delete('/my-feature/{id}', 'MyController', 'delete', ['AuthMiddleware']);
```

## Testing

Run the test suite:
```bash
php playpbnow-api/test_router.php
```

Test a single endpoint:
```bash
curl -X GET http://localhost/api/health
curl -X POST http://localhost/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"pass123"}'
```

## HTTP Status Codes

- **200** - OK (successful GET, PUT)
- **201** - Created (successful POST)
- **204** - No Content (successful DELETE)
- **400** - Bad Request (validation error)
- **401** - Unauthorized (auth required)
- **403** - Forbidden (insufficient permissions)
- **404** - Not Found (resource not found)
- **422** - Unprocessable Entity (validation error with details)
- **429** - Too Many Requests (rate limited)
- **500** - Internal Server Error

## Database Access

Use db_config.php helper functions:

```php
// Query (returns statement)
$stmt = dbQuery("INSERT INTO users (name) VALUES (?)", [$name]);

// Get single row
$user = dbGetRow("SELECT * FROM users WHERE id = ?", [$id]);

// Get all rows
$users = dbGetAll("SELECT * FROM users LIMIT ?, ?", [$offset, $limit]);

// Insert with auto-increment
$id = dbInsert("INSERT INTO users (name) VALUES (?)", [$name]);
```

## Next Steps (Phase 2)

Agent 3 will implement:
1. All controller action methods (currently return 501 Not Implemented)
2. Database queries and business logic
3. Unit and integration tests
4. API documentation and Swagger/OpenAPI spec
5. Advanced features: caching, search, filtering, sorting

## Notes

- All code uses MySQLi prepared statements (secure against SQL injection)
- Timezone automatically set to Pacific (America/Los_Angeles)
- No hardcoded secrets (use db_config.php from vault)
- All requests/errors logged to files in logs/
- Rate limiting uses file-based counter (Redis support TODO)
- CORS enabled for all origins (configure as needed)

## Troubleshooting

**Route not found (404):**
- Check path matches exactly (case-sensitive)
- Ensure route is registered in routes.php
- Check path parameters use {name} syntax

**Validation errors (422):**
- Check field names match request data
- Review validation rules syntax
- Use error response details to identify failing fields

**Unauthorized (401):**
- Check Authorization header or X-Auth-Token is present
- Verify token is valid and not expired
- Auth middleware requires token verification (TODO)

**Rate limited (429):**
- Wait 60 seconds, then retry
- Rate limit is 100 requests/minute per IP
- Check logs/rate_limit/ for counter files
