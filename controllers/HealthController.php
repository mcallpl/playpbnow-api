<?php
/**
 * HealthController - Health check endpoints for API monitoring
 */

require_once __DIR__ . '/../BaseController.php';

class HealthController extends BaseController {
    /**
     * GET /api/health - Simple health check
     */
    public function check() {
        $this->response([
            'status' => 'healthy',
            'timestamp' => date('c'),
            'version' => '1.0.0'
        ]);
    }

    /**
     * GET /api/version - Get API version
     */
    public function version() {
        $this->response([
            'version' => '1.0.0',
            'name' => 'PlayPBNow API',
            'environment' => defined('ENVIRONMENT') ? ENVIRONMENT : 'production'
        ]);
    }
}
?>
