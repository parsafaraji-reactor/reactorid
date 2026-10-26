<?php

namespace Reactor\Kyc;

if (!defined('WHMCS')) {
    die('Access denied');
}

class ClientArea
{
    public static function render($userId, $vars)
    {
        if (empty($_SESSION['reactorid_csrf'])) {
            $_SESSION['reactorid_csrf'] = bin2hex(random_bytes(16));
        }
        $csrf = $_SESSION['reactorid_csrf'];

        $configured = Settings::configured();
        $state = $userId > 0 ? Flow::state($userId) : ['step' => 1, 'verified' => false, 'status' => 'new'];
        $assetBase = 'modules/addons/reactorid/assets/';
        $endpoint = 'index.php?m=reactorid';

        $boot = [
            'endpoint' => $endpoint,
            'csrf'     => $csrf,
            'state'    => $state,
            'configured' => $configured,
            'continueUrl' => 'cart.php',
            'clientUrl' => 'clientarea.php',
        ];
        $bootJson = htmlspecialchars(json_encode($boot, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        ob_start();
        ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase); ?>reactorid.css?v=121">
<div id="reactorid-root" data-boot="<?php echo $bootJson; ?>">
  <div class="afk-card">
    <div class="afk-head">
      <div class="afk-head-icon">
        <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5z"></path><path d="m9 12 2 2 4-4"></path></svg>
      </div>
      <div class="afk-head-text">
        <h2>احراز هویت حساب</h2>
        <p>برای فعال شدن امکان خرید، هویت خود را در چند مرحله‌ی ساده تأیید کنید.</p>
      </div>
    </div>

    <?php if (!$configured): ?>
      <div class="afk-alert afk-alert-warn">سرویس احراز هویت هنوز توسط مدیر سایت پیکربندی نشده است. لطفاً بعداً تلاش کنید.</div>
    <?php endif; ?>

    <div class="afk-steps">
      <div class="afk-step" data-step="1"><span class="afk-dot">1</span><em>موبایل</em></div>
      <div class="afk-line"></div>
      <div class="afk-step" data-step="2"><span class="afk-dot">2</span><em>هویت</em></div>
      <div class="afk-line"></div>
      <div class="afk-step" data-step="3"><span class="afk-dot">3</span><em>کارت بانکی</em></div>
      <div class="afk-line"></div>
      <div class="afk-step" data-step="4"><span class="afk-dot">✓</span><em>تکمیل</em></div>
    </div>

    <div class="afk-body">

      <section class="afk-panel" data-panel="1">
        <h3>تأیید شماره موبایل</h3>
        <p class="afk-muted">شماره‌ای که به نام خودتان است و کد تأیید روی آن ارسال می‌شود.</p>
        <div class="afk-field afk-mobile-field">
          <label>شماره موبایل</label>
          <input type="tel" id="afk-mobile" inputmode="numeric" maxlength="11" placeholder="09xxxxxxxxx" autocomplete="tel">
        </div>
        <button type="button" class="afk-btn afk-primary" id="afk-send-otp">ارسال کد تأیید</button>

        <div class="afk-otp-box" hidden>
          <p class="afk-muted">کد ۵ رقمی ارسال‌شده به <bdi id="afk-otp-target" dir="ltr"></bdi> را وارد کنید.</p>
          <div class="afk-otp-inputs" dir="ltr">
            <input type="tel" inputmode="numeric" maxlength="1" class="afk-otp-digit">
            <input type="tel" inputmode="numeric" maxlength="1" class="afk-otp-digit">
            <input type="tel" inputmode="numeric" maxlength="1" class="afk-otp-digit">
            <input type="tel" inputmode="numeric" maxlength="1" class="afk-otp-digit">
            <input type="tel" inputmode="numeric" maxlength="1" class="afk-otp-digit">
          </div>
          <div class="afk-otp-actions">
            <button type="button" class="afk-btn afk-primary" id="afk-verify-otp">تأیید کد</button>
            <button type="button" class="afk-link" id="afk-resend" disabled>ارسال مجدد (<span id="afk-timer">90</span>)</button>
            <button type="button" class="afk-link" id="afk-edit-mobile">ویرایش شماره</button>
          </div>
        </div>
      </section>

      <section class="afk-panel" data-panel="2" hidden>
        <h3>تأیید هویت</h3>
        <p class="afk-muted">کد ملی و تاریخ تولد خود را وارد کنید. تطابق کد ملی و شماره موبایل از طریق سامانه‌ی شاهکار بررسی می‌شود.</p>
        <div class="afk-field">
          <label>کد ملی</label>
          <input type="tel" id="afk-national" inputmode="numeric" maxlength="10" placeholder="کد ملی ۱۰ رقمی">
        </div>
        <div class="afk-field">
          <label>تاریخ تولد</label>
          <div class="afk-date" dir="rtl">
            <select id="afk-bd-day"></select>
            <select id="afk-bd-month"></select>
            <select id="afk-bd-year"></select>
          </div>
        </div>
        <button type="button" class="afk-btn afk-primary" id="afk-verify-identity">استعلام و تأیید هویت</button>
      </section>

      <section class="afk-panel" data-panel="3" hidden>
        <h3>تأیید کارت بانکی</h3>
        <p class="afk-muted">کارت بانکی باید به نام خودتان باشد. تطابق کارت با کد ملی بررسی می‌شود.</p>
        <div class="afk-field">
          <label>شماره کارت</label>
          <input type="tel" id="afk-card" inputmode="numeric" maxlength="19" placeholder="xxxx xxxx xxxx xxxx" dir="ltr">
          <span class="afk-bank" id="afk-bank"></span>
        </div>
        <button type="button" class="afk-btn afk-primary" id="afk-verify-card">تأیید کارت</button>
      </section>

      <section class="afk-panel afk-done" data-panel="4" hidden>
        <div class="afk-check">
          <svg viewBox="0 0 24 24" width="46" height="46" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
        </div>
        <h3>احراز هویت شما کامل شد</h3>
        <p class="afk-muted">حساب شما تأیید شد و اکنون می‌توانید خرید کنید.</p>
        <div class="afk-summary" id="afk-summary"></div>
        <a class="afk-btn afk-primary" id="afk-continue" href="cart.php">ادامه و خرید</a>
      </section>

      <div class="afk-msg" id="afk-msg" hidden></div>
    </div>
  </div>
</div>
<script src="<?php echo htmlspecialchars($assetBase); ?>reactorid.js?v=121"></script>
        <?php
        return ob_get_clean();
    }
}
