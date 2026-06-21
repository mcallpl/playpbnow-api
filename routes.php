<?php
/**
 * API Routes - Central route definitions
 * Maps HTTP requests to controller actions
 * Format: 'METHOD /path' => ['ControllerClass', 'actionMethod']
 *
 * Route parameters use curly braces: /users/{id}
 * These are automatically extracted and passed to controller as request body parameters
 */

// Initialize router
require_once __DIR__ . '/Router.php';

$router = Router::getInstance('/api');

// ============================================
// AUTHENTICATION ROUTES
// ============================================
$router->post('/auth/register', 'AuthController', 'register');
$router->post('/auth/login', 'AuthController', 'login');
$router->post('/auth/logout', 'AuthController', 'logout', ['AuthMiddleware']);
$router->post('/auth/refresh-token', 'AuthController', 'refreshToken', ['AuthMiddleware']);
$router->post('/auth/forgot-password', 'AuthController', 'forgotPassword');
$router->post('/auth/reset-password', 'AuthController', 'resetPassword');
$router->post('/auth/change-password', 'AuthController', 'changePassword', ['AuthMiddleware']);
$router->post('/auth/verify-code', 'AuthController', 'verifyCode');

// ============================================
// USER ROUTES
// ============================================
$router->get('/users/profile', 'UserController', 'getProfile', ['AuthMiddleware']);
$router->put('/users/profile', 'UserController', 'updateProfile', ['AuthMiddleware']);
$router->get('/users/{id}', 'UserController', 'getUser');
$router->delete('/users/{id}', 'UserController', 'deleteUser', ['AuthMiddleware']);
$router->post('/users/verify-phone', 'UserController', 'verifyPhone');

// ============================================
// PLAYER ROUTES
// ============================================
$router->get('/players', 'PlayerController', 'list', ['AuthMiddleware']);
$router->post('/players', 'PlayerController', 'create', ['AuthMiddleware']);
$router->get('/players/{id}', 'PlayerController', 'get', ['AuthMiddleware']);
$router->put('/players/{id}', 'PlayerController', 'update', ['AuthMiddleware']);
$router->delete('/players/{id}', 'PlayerController', 'delete', ['AuthMiddleware']);
$router->post('/players/{id}/stats', 'PlayerController', 'getStats', ['AuthMiddleware']);

// ============================================
// POOL PLAYERS ROUTES
// ============================================
$router->get('/pool/players', 'PoolController', 'search', ['AuthMiddleware']);
$router->get('/pool/players/{id}', 'PoolController', 'getPlayer', ['AuthMiddleware']);
$router->post('/pool/import', 'PoolController', 'importPlayers', ['AuthMiddleware']);

// ============================================
// MATCHES ROUTES
// ============================================
$router->get('/matches', 'MatchController', 'list', ['AuthMiddleware']);
$router->post('/matches', 'MatchController', 'create', ['AuthMiddleware']);
$router->get('/matches/{id}', 'MatchController', 'get', ['AuthMiddleware']);
$router->put('/matches/{id}', 'MatchController', 'update', ['AuthMiddleware']);
$router->delete('/matches/{id}', 'MatchController', 'delete', ['AuthMiddleware']);
$router->post('/matches/{id}/scores', 'MatchController', 'updateScores', ['AuthMiddleware']);
$router->post('/matches/{id}/end', 'MatchController', 'endMatch', ['AuthMiddleware']);

// ============================================
// GROUPS ROUTES
// ============================================
$router->get('/groups', 'GroupController', 'list', ['AuthMiddleware']);
$router->post('/groups', 'GroupController', 'create', ['AuthMiddleware']);
$router->get('/groups/{id}', 'GroupController', 'get', ['AuthMiddleware']);
$router->put('/groups/{id}', 'GroupController', 'update', ['AuthMiddleware']);
$router->delete('/groups/{id}', 'GroupController', 'delete', ['AuthMiddleware']);
$router->post('/groups/{id}/members', 'GroupController', 'addMember', ['AuthMiddleware']);
$router->delete('/groups/{id}/members/{memberId}', 'GroupController', 'removeMember', ['AuthMiddleware']);

// ============================================
// INVITES ROUTES
// ============================================
$router->get('/invites', 'InviteController', 'list', ['AuthMiddleware']);
$router->post('/invites', 'InviteController', 'create', ['AuthMiddleware']);
$router->get('/invites/{id}', 'InviteController', 'get', ['AuthMiddleware']);
$router->post('/invites/{id}/send', 'InviteController', 'send', ['AuthMiddleware']);
$router->post('/invites/{code}/respond', 'InviteController', 'respond');
$router->delete('/invites/{id}', 'InviteController', 'delete', ['AuthMiddleware']);

// ============================================
// SMS ROUTES
// ============================================
$router->get('/sms/balance', 'SMSController', 'getBalance', ['AuthMiddleware']);
$router->post('/sms/purchase', 'SMSController', 'purchaseCredits', ['AuthMiddleware']);
$router->get('/sms/history', 'SMSController', 'getHistory', ['AuthMiddleware']);
$router->post('/sms/webhook', 'SMSController', 'webhook');

// ============================================
// SUBSCRIPTION ROUTES
// ============================================
$router->get('/subscription/status', 'SubscriptionController', 'getStatus', ['AuthMiddleware']);
$router->post('/subscription/upgrade', 'SubscriptionController', 'upgrade', ['AuthMiddleware']);
$router->post('/subscription/cancel', 'SubscriptionController', 'cancel', ['AuthMiddleware']);
$router->post('/subscription/webhook', 'SubscriptionController', 'stripeWebhook');

// ============================================
// HEALTH CHECK ROUTES
// ============================================
$router->get('/health', 'HealthController', 'check');
$router->get('/version', 'HealthController', 'version');

// ============================================
// ADMIN ROUTES (to be implemented by Agent 3+)
// ============================================
// $router->get('/admin/users', 'AdminController', 'listUsers', ['AuthMiddleware', 'AdminMiddleware']);
// $router->get('/admin/stats', 'AdminController', 'getStats', ['AuthMiddleware', 'AdminMiddleware']);

// ============================================
// CATCH-ALL FOR UNMATCHED ROUTES
// ============================================
// This is handled automatically by Router::notFound()

return $router;
?>
