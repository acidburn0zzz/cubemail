<?php
namespace Kolab2FA\Log;

class RcubeLogger implements Logger {
    public function log($level, $message) {
        error_log($message);
    }
}
?>
