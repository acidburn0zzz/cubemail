<?php
namespace Kolab2FA\Log;

class Syslog implements Logger {
    public function log($level, $message) {
        error_log($message);
    }
}
?>
