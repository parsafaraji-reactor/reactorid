<?php

namespace Reactor\Kyc;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access denied');
}

class Otp
{
    const LENGTH = 5;
    const TTL = 120;
    const RESEND_COOLDOWN = 90;
    const MAX_ATTEMPTS = 5;

    public static function send($userId, $mobile)
    {
        $userId = (int) $userId;
        $existing = Capsule::table(Verification::T_OTPS)->where('userid', $userId)->first();
        if ($existing && !empty($existing->last_sent_at)) {
            $elapsed = time() - strtotime($existing->last_sent_at);
            if ($elapsed < self::RESEND_COOLDOWN) {
                return [
                    'ok'       => false,
                    'message'  => 'برای ارسال مجدد کمی صبر کنید.',
                    'cooldown' => self::RESEND_COOLDOWN - $elapsed,
                ];
            }
        }

        $code = str_pad((string) random_int(0, 99999), self::LENGTH, '0', STR_PAD_LEFT);
        $now = date('Y-m-d H:i:s');
        $data = [
            'userid'       => $userId,
            'mobile'       => $mobile,
            'code_hash'    => password_hash($code, PASSWORD_DEFAULT),
            'attempts'     => 0,
            'expires_at'   => date('Y-m-d H:i:s', time() + self::TTL),
            'last_sent_at' => $now,
            'created_at'   => $now,
        ];
        if ($existing) {
            Capsule::table(Verification::T_OTPS)->where('userid', $userId)->update($data);
        } else {
            Capsule::table(Verification::T_OTPS)->insert($data);
        }

        $res = SmsIr::sendVerify($mobile, $code);
        if (!$res['ok']) {
            Capsule::table(Verification::T_OTPS)->where('userid', $userId)->update([
                'last_sent_at' => null,
                'expires_at'   => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => false, 'message' => $res['message'], 'detail' => isset($res['detail']) ? $res['detail'] : ''];
        }
        return ['ok' => true, 'message' => 'کد تأیید ارسال شد.', 'cooldown' => self::RESEND_COOLDOWN];
    }

    public static function verify($userId, $mobile, $code)
    {
        $userId = (int) $userId;
        $code = Validate::digits($code);
        $row = Capsule::table(Verification::T_OTPS)->where('userid', $userId)->first();
        if (!$row || $row->mobile !== $mobile) {
            return ['ok' => false, 'message' => 'ابتدا کد تأیید را دریافت کنید.'];
        }
        if (strtotime($row->expires_at) < time()) {
            return ['ok' => false, 'message' => 'کد تأیید منقضی شده است. دوباره تلاش کنید.'];
        }
        if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'message' => 'تعداد تلاش بیش از حد مجاز. کد جدید دریافت کنید.'];
        }
        Capsule::table(Verification::T_OTPS)->where('userid', $userId)->increment('attempts');
        if (!password_verify($code, $row->code_hash)) {
            return ['ok' => false, 'message' => 'کد تأیید نادرست است.'];
        }
        Capsule::table(Verification::T_OTPS)->where('userid', $userId)->delete();
        return ['ok' => true, 'message' => 'شماره موبایل تأیید شد.'];
    }
}
