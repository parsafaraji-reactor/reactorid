<?php

use Reactor\Kyc\Settings;
use Reactor\Kyc\Verification;

if (!defined('WHMCS')) {
    die('Access denied');
}

require_once __DIR__ . '/lib/bootstrap.php';

/**
 * Until the account is verified (and its card registered when card-lock is on),
 * every client area page is redirected to the verification page. Only the
 * support ticket pages, the verification page itself and logout stay open.
 */
function reactorid_allowed_script($script, $module)
{
    if ($script === 'index.php' && $module === 'reactorid') {
        return true;
    }
    $open = array(
        'supporttickets.php',
        'submitticket.php',
        'viewticket.php',
        'logout.php',
        'dologin.php',
        'login.php',
        'pwreset.php',
    );
    return in_array($script, $open, true);
}

$reactoridGate = function ($vars) {
    if (!Settings::isOn('enforce_checkout')) {
        return;
    }
    $uid = (int) (isset($_SESSION['uid']) ? $_SESSION['uid'] : 0);
    if ($uid <= 0 || !Settings::configured() || Verification::isReady($uid)) {
        return;
    }
    $script = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
    $module = isset($_GET['m']) ? (string) $_GET['m'] : '';
    if (reactorid_allowed_script($script, $module)) {
        return;
    }
    header('Location: index.php?m=reactorid', true, 302);
    exit;
};

add_hook('ClientAreaPage', 1, $reactoridGate);
add_hook('ClientAreaPageCart', 1, $reactoridGate);

add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    if (!Settings::isOn('enforce_checkout')) {
        return [];
    }
    $uid = (int) (isset($_SESSION['uid']) ? $_SESSION['uid'] : 0);

    if ($uid <= 0) {
        if (Settings::isOn('block_guest')) {
            return ['برای تکمیل خرید ابتدا وارد حساب کاربری شوید و احراز هویت را انجام دهید.'];
        }
        return [];
    }
    if (!Settings::configured()) {
        return [];
    }
    if (Verification::isReady($uid)) {
        return [];
    }
    return ['برای تکمیل خرید ابتدا باید احراز هویت حساب را کامل کنید. <a href="index.php?m=reactorid">شروع احراز هویت</a>'];
});

add_hook('ClientAreaHomepagePanels', 1, function ($panels) {
    $uid = (int) (isset($_SESSION['uid']) ? $_SESSION['uid'] : 0);
    if ($uid <= 0 || !Settings::configured()) {
        return;
    }
    $verified = Verification::isReady($uid);
    $panel = $panels->addChild('reactorid-status', [
        'label' => 'احراز هویت',
        'order' => 1,
        'icon' => 'fa-id-card',
    ]);
    if ($verified) {
        $panel->setBodyHtml('<div style="text-align:center;padding:14px 8px"><div style="color:#16b364;font-weight:800;font-size:15px;margin-bottom:4px">حساب شما احراز هویت شده است</div><div style="color:#8b93a7;font-size:12px">امکان خرید فعال است.</div></div>');
    } else {
        $panel->setBodyHtml(
            '<div style="text-align:center;padding:12px 8px">'
            . '<div style="color:#e4483d;font-weight:800;font-size:14px;margin-bottom:8px">حساب شما هنوز احراز هویت نشده است</div>'
            . '<div style="color:#8b93a7;font-size:12px;margin-bottom:12px">برای خرید سرویس، ابتدا هویت خود را تأیید کنید.</div>'
            . '<a href="index.php?m=reactorid" class="btn btn-primary btn-sm" style="font-weight:700">تکمیل احراز هویت</a>'
            . '</div>'
        );
    }
});

add_hook('AdminHomeWidgets', 1, function () {
    if (class_exists('\\Reactor\\Kyc\\StatsWidget')) {
        return new \Reactor\Kyc\StatsWidget();
    }
});
