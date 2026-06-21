<?php
require_once __DIR__ . '/../BaseController.php';

class SubscriptionController extends BaseController {
    public function getStatus() {
        $this->error('Not yet implemented', 'NOT_IMPLEMENTED', 501);
    }
    public function upgrade() {
        $this->error('Not yet implemented', 'NOT_IMPLEMENTED', 501);
    }
    public function cancel() {
        $this->error('Not yet implemented', 'NOT_IMPLEMENTED', 501);
    }
    public function stripeWebhook() {
        $this->error('Not yet implemented', 'NOT_IMPLEMENTED', 501);
    }
}
?>
