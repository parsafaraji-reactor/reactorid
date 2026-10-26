<?php

namespace Reactor\Kyc;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access denied');
}

class Flow
{
    const MAX_OTP_PER_DAY = 15;
    const MAX_IDENTITY_PER_HOUR = 25;
    const MAX_CARD_PER_HOUR = 25;

    public static function handle($action, $userId, array $input)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return ['ok' => false, 'message' => 'برای احراز هویت ابتدا وارد حساب شوید.'];
        }
        if (!Settings::configured()) {
            return ['ok' => false, 'message' => 'سرویس احراز هویت هنوز توسط مدیر پیکربندی نشده است.'];
        }
        if (Verification::isReady($userId) && $action !== 'status') {
            return ['ok' => true, 'done' => true, 'message' => 'احراز هویت شما قبلاً کامل شده است.'];
        }

        switch ($action) {
            case 'send_otp':
                return self::sendOtp($userId, $input);
            case 'verify_otp':
                return self::verifyOtp($userId, $input);
            case 'verify_identity':
                return self::verifyIdentity($userId, $input);
            case 'verify_card':
                return self::verifyCard($userId, $input);
            case 'status':
                return ['ok' => true, 'state' => self::state($userId)];
            default:
                return ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
        }
    }

    protected static function sendOtp($userId, $input)
    {
        $mobile = Validate::mobile(isset($input['mobile']) ? $input['mobile'] : '');
        if (!$mobile) {
            return ['ok' => false, 'message' => 'شماره موبایل معتبر نیست.'];
        }
        if (Verification::isBlacklisted('mobile', $mobile)) {
            Verification::log($userId, 'mobile', 'blocked', 'blacklisted mobile');
            return ['ok' => false, 'message' => 'امکان استفاده از این شماره وجود ندارد. با پشتیبانی تماس بگیرید.'];
        }
        if (Verification::rateLimited($userId, ['mobile'], self::MAX_OTP_PER_DAY, 86400, ['otp_sent'])) {
            return ['ok' => false, 'message' => 'تعداد ارسال پیامک امروز به حد مجاز رسیده است. فردا دوباره تلاش کنید.'];
        }
        $row = Verification::ensure($userId);
        $previousMobile = isset($row['mobile']) ? (string) $row['mobile'] : '';
        if ($previousMobile !== '' && $previousMobile !== $mobile) {
            
            Verification::update($userId, [
                'mobile'             => $mobile,
                'mobile_verified_at' => null,
                'national_code'      => null,
                'birth_date'         => null,
                'first_name'         => null,
                'last_name'          => null,
                'father_name'        => null,
                'card_mask'          => null,
                'card_hash'          => null,
                'card_sha256'        => null,
                'card_number_enc'    => null,
                'card_bank'          => null,
                'identity_matched'   => 0,
                'shahkar_matched'    => 0,
                'card_matched'       => 0,
                'name_match'         => null,
                'verified_at'        => null,
                'status'             => Verification::STATUS_NEW,
            ]);
        } else {
            Verification::update($userId, ['mobile' => $mobile]);
        }
        $res = Otp::send($userId, $mobile);
        Verification::log($userId, 'mobile', $res['ok'] ? 'otp_sent' : 'otp_send_failed', !empty($res['detail']) ? $res['detail'] : $res['message']);
        unset($res['detail']);
        return $res;
    }

    protected static function verifyOtp($userId, $input)
    {
        $row = Verification::get($userId);
        $mobile = $row && !empty($row['mobile']) ? $row['mobile'] : Validate::mobile(isset($input['mobile']) ? $input['mobile'] : '');
        if (!$mobile) {
            return ['ok' => false, 'message' => 'ابتدا شماره موبایل را وارد کنید.'];
        }
        $res = Otp::verify($userId, $mobile, isset($input['code']) ? $input['code'] : '');
        if (!$res['ok']) {
            Verification::log($userId, 'mobile', 'otp_failed', $res['message']);
            return $res;
        }
        Verification::update($userId, ['mobile_verified_at' => date('Y-m-d H:i:s')]);
        Verification::recompute($userId);
        Verification::log($userId, 'mobile', 'verified', 'mobile verified');
        return ['ok' => true, 'message' => $res['message'], 'state' => self::state($userId)];
    }

    protected static function verifyIdentity($userId, $input)
    {
        $row = Verification::get($userId);
        if (!$row || empty($row['mobile_verified_at'])) {
            return ['ok' => false, 'message' => 'ابتدا شماره موبایل را تأیید کنید.'];
        }
        $nationalCode = Validate::nationalCode(isset($input['national_code']) ? $input['national_code'] : '');
        if (!$nationalCode) {
            return ['ok' => false, 'message' => 'کد ملی معتبر نیست.'];
        }
        $birthDate = Validate::birthDate(isset($input['birth_date']) ? $input['birth_date'] : '');
        if (!$birthDate) {
            return ['ok' => false, 'message' => 'تاریخ تولد معتبر نیست.'];
        }
        if (Verification::isBlacklisted('national_code', $nationalCode)) {
            Verification::log($userId, 'identity', 'blocked', 'blacklisted national code');
            return ['ok' => false, 'message' => 'امکان استفاده از این کد ملی وجود ندارد. با پشتیبانی تماس بگیرید.'];
        }
        if (Verification::nationalCodeTaken($nationalCode, $userId)) {
            Verification::log($userId, 'identity', 'duplicate', 'national code already verified for another account');
            return ['ok' => false, 'message' => 'این کد ملی قبلاً برای حساب دیگری احراز شده است.'];
        }
        if (Verification::rateLimited($userId, ['shahkar', 'identity'], self::MAX_IDENTITY_PER_HOUR)) {
            return ['ok' => false, 'message' => 'تعداد تلاش برای استعلام هویت زیاد بوده است. کمی بعد دوباره تلاش کنید.'];
        }

        $mobile = $row['mobile'];
        $shahkar = Zohal::shahkar($mobile, $nationalCode);
        if (!$shahkar['ok']) {
            Verification::log($userId, 'shahkar', 'error', $shahkar['error_code'] . ' ' . $shahkar['message']);
            return ['ok' => false, 'message' => self::friendly($shahkar, 'استعلام شاهکار ناموفق بود.')];
        }
        if ($shahkar['matched'] !== true) {
            Verification::log($userId, 'shahkar', 'not_matched', 'shahkar not matched');
            return ['ok' => false, 'message' => 'شماره موبایل به نام این کد ملی ثبت نشده است. از سیم‌کارت به نام خودتان استفاده کنید.'];
        }

        Verification::update($userId, [
            'national_code'    => $nationalCode,
            'birth_date'       => $birthDate,
            'first_name'       => '',
            'last_name'        => '',
            'father_name'      => '',
            'identity_matched' => 1,
            'shahkar_matched'  => 1,
            'name_match'       => null,
        ]);
        Verification::recompute($userId);
        Verification::log($userId, 'identity', 'verified', 'shahkar matched; identity stage accepted for card inquiry');
        return [
            'ok'      => true,
            'message' => 'تطبیق کد ملی و شماره موبایل تأیید شد. حالا شماره کارت را وارد کنید.',
            'state'   => self::state($userId),
        ];
    }

    protected static function verifyCard($userId, $input)
    {
        $row = Verification::get($userId);
        if (!$row || (int) $row['identity_matched'] !== 1 || (int) $row['shahkar_matched'] !== 1) {
            return ['ok' => false, 'message' => 'ابتدا مرحله‌ی هویت را کامل کنید.'];
        }
        $card = Validate::cardNumber(isset($input['card_number']) ? $input['card_number'] : '');
        if (!$card) {
            return ['ok' => false, 'message' => 'شماره کارت معتبر نیست.'];
        }
        if (Verification::isBlacklisted('card', $card)) {
            Verification::log($userId, 'card', 'blocked', 'blacklisted card');
            return ['ok' => false, 'message' => 'امکان استفاده از این کارت وجود ندارد. با پشتیبانی تماس بگیرید.'];
        }
        if (Verification::rateLimited($userId, ['card'], self::MAX_CARD_PER_HOUR)) {
            return ['ok' => false, 'message' => 'تعداد تلاش برای استعلام کارت زیاد بوده است. کمی بعد دوباره تلاش کنید.'];
        }

        $res = Zohal::checkCard($row['national_code'], $row['birth_date'], $card);
        if (!$res['ok']) {
            Verification::log($userId, 'card', 'error', $res['error_code'] . ' ' . $res['message']);
            return ['ok' => false, 'message' => self::friendly($res, 'استعلام کارت ناموفق بود.')];
        }
        if ($res['matched'] !== true) {
            Verification::log($userId, 'card', 'not_matched', 'card not matched');
            return ['ok' => false, 'message' => 'کارت بانکی به نام صاحب این کد ملی نیست. از کارت به نام خودتان استفاده کنید.'];
        }

        Verification::update($userId, [
            'card_mask'       => Validate::maskCard($card),
            'card_hash'       => Verification::hash($card),
            'card_sha256'     => hash('sha256', $card),
            'card_number_enc' => Crypto::encrypt($card),
            'card_bank'       => Banks::name($card),
            'card_matched'    => 1,
        ]);
        Verification::recompute($userId);
        Verification::log($userId, 'card', 'verified', 'card matched');
        return ['ok' => true, 'done' => true, 'message' => 'احراز هویت با موفقیت کامل شد.', 'state' => self::state($userId)];
    }

    public static function state($userId)
    {
        $row = Verification::get($userId);
        $step = Verification::currentStep($row);
        $needCard = Verification::needsCardRegistration($row);
        return [
            'step'       => $needCard ? 3 : $step,
            'status'     => $row ? $row['status'] : Verification::STATUS_NEW,
            'verified'   => Verification::isVerified($userId),
            'ready'      => Verification::isReady($userId),
            'needCard'   => $needCard,
            'mobile'     => $row && !empty($row['mobile']) ? self::maskMobile($row['mobile']) : '',
            'national'   => $row && !empty($row['national_code']) ? self::maskNational($row['national_code']) : '',
            'fullname'   => $row ? trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) : '',
            'card_mask'  => $row && !empty($row['card_mask']) ? $row['card_mask'] : '',
            'card_bank'  => $row && !empty($row['card_bank']) ? $row['card_bank'] : '',
        ];
    }

    protected static function matchAccountName($userId, $firstName, $lastName)
    {
        $client = Capsule::table('tblclients')->where('id', (int) $userId)->first();
        if (!$client) {
            return null;
        }
        $accFirst = self::normalizeName($client->firstname);
        $accLast = self::normalizeName($client->lastname);
        if ($accFirst === '' || $accLast === '') {
            return null;
        }
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $accFirst . $accLast)) {
            return null;
        }
        $match = (self::normalizeName($firstName) === $accFirst && self::normalizeName($lastName) === $accLast);
        return $match ? 1 : 0;
    }

    protected static function normalizeName($name)
    {
        $name = (string) $name;
        $name = str_replace(['ي', 'ك', 'ۀ', 'ة', 'آ', 'أ', 'إ'], ['ی', 'ک', 'ه', 'ه', 'ا', 'ا', 'ا'], $name);
        $name = preg_replace('/[\x{200c}\s]+/u', '', $name);
        return trim($name);
    }

    protected static function friendly($res, $fallback)
    {
        return 'در حال حاضر امکان بررسی اطلاعات وجود ندارد. لطفاً چند دقیقه دیگر دوباره تلاش کنید. در صورت تکرار مشکل با پشتیبانی تماس بگیرید.';
    }

    protected static function maskMobile($m)
    {
        if (strlen($m) !== 11) {
            return $m;
        }
        return substr($m, 0, 4) . '***' . substr($m, 7);
    }

    protected static function maskNational($n)
    {
        if (strlen($n) !== 10) {
            return $n;
        }
        return substr($n, 0, 3) . '****' . substr($n, 7);
    }
}
