<?php

/**
 * ReactorID — Iranian identity verification (KYC) addon for WHMCS.
 *
 * Verifies a customer's identity through Zohal inquiries (Shahkar + card/
 * national-code matching), gates the client area until verification is
 * complete, and locks payments to the customer's own verified bank card.
 *
 * @package  ReactorID
 * @author   Reactor
 * @version  1.0.0
 * @see      README.md
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use Reactor\Kyc\Settings;
use Reactor\Kyc\Verification;
use Reactor\Kyc\Flow;
use Reactor\Kyc\Installer;
use Reactor\Kyc\AdminArea;
use Reactor\Kyc\ClientArea;

function reactorid_config()
{
    return [
        'name' => 'احراز هویت ری‌اکتور',
        'description' => 'احراز هویت کاربران با تطبیق شاهکار (کد ملی و موبایل) و تطبیق کارت بانکی با کد ملی و تاریخ تولد؛ جلوگیری از خرید تا تکمیل احراز.',
        'author' => 'Reactor',
        'language' => 'persian',
        'version' => '1.0.0',
        'fields' => [
            'zohal_token' => [
                'FriendlyName' => 'توکن سرویس زحل',
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'توکن دسترسی (Bearer) پنل زحل',
            ],
            'smsir_api_key' => [
                'FriendlyName' => 'کلید API پیامک (SMS.ir)',
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'همان کلید استفاده‌شده در ورود پیامکی',
            ],
            'smsir_template_id' => [
                'FriendlyName' => 'شناسه قالب کد تأیید',
                'Type' => 'text',
                'Size' => '20',
                'Description' => 'شناسه قالب verify در پنل SMS.ir',
            ],
            'smsir_param_name' => [
                'FriendlyName' => 'نام پارامتر کد',
                'Type' => 'text',
                'Size' => '20',
                'Default' => 'CODE',
                'Description' => 'نام پارامتر کد در قالب پیامک',
            ],
            'enforce_checkout' => [
                'FriendlyName' => 'مسدودسازی تسویه',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'تا احراز کامل نشود، تکمیل خرید اجازه داده نمی‌شود',
            ],
            'block_guest' => [
                'FriendlyName' => 'مسدودسازی خرید مهمان',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'کاربر مهمان (بدون ورود) اجازه‌ی تکمیل خرید ندارد',
            ],
            'enforce_payment_card' => [
                'FriendlyName' => 'قفل کارت پرداخت',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'پرداخت درگاه فقط با همان کارتی که در احراز هویت ثبت شده مجاز است (در حال حاضر زرین‌پال)',
            ],
        ],
    ];
}

function reactorid_activate()
{
    try {
        Installer::install();
        return ['status' => 'success', 'description' => 'افزونه احراز هویت ری‌اکتور فعال شد. تنظیمات زحل و پیامک را کامل کنید.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'خطا در فعال‌سازی: ' . $e->getMessage()];
    }
}

function reactorid_deactivate()
{
    return ['status' => 'success', 'description' => 'افزونه غیرفعال شد. داده‌های احراز حفظ شدند.'];
}

function reactorid_upgrade($vars)
{
    try {
        Installer::install();
    } catch (\Throwable $e) {
        
    }
}

function reactorid_output($vars)
{
    echo AdminArea::render($vars);
}

function reactorid_clientarea($vars)
{
    $userId = (int) (isset($_SESSION['uid']) ? $_SESSION['uid'] : 0);

    if (isset($_REQUEST['kycajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        $token = isset($_SESSION['reactorid_csrf']) ? $_SESSION['reactorid_csrf'] : '';
        $sent = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
        if ($token === '' || !hash_equals($token, $sent)) {
            echo json_encode(['ok' => false, 'message' => 'نشست نامعتبر است. صفحه را تازه کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $action = preg_replace('/[^a-z_]/', '', (string) $_REQUEST['kycajax']);
        $out = Flow::handle($action, $userId, $_POST);
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $html = ClientArea::render($userId, $vars);

    return [
        'pagetitle' => 'احراز هویت',
        'breadcrumb' => ['index.php?m=reactorid' => 'احراز هویت'],
        'templatefile' => 'clientarea',
        'requirelogin' => true,
        'forcessl' => true,
        'vars' => [
            'kycContent' => $html,
        ],
    ];
}
