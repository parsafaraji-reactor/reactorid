<?php

if (!defined('WHMCS')) {
    die('Access denied');
}

require_once __DIR__ . '/Validate.php';
require_once __DIR__ . '/Banks.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/Zohal.php';
require_once __DIR__ . '/SmsIr.php';
require_once __DIR__ . '/Verification.php';
require_once __DIR__ . '/Otp.php';
require_once __DIR__ . '/Flow.php';
require_once __DIR__ . '/Installer.php';
require_once __DIR__ . '/ClientArea.php';
require_once __DIR__ . '/AdminArea.php';

if (class_exists('\\WHMCS\\Module\\AbstractWidget')) {
    require_once __DIR__ . '/StatsWidget.php';
}
