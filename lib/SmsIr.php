<?php

namespace Reactor\Kyc;

if (!defined('WHMCS')) {
    die('Access denied');
}

class SmsIr
{
    public static function sendVerify($mobile, $code)
    {
        $apiKey = trim(Settings::get('smsir_api_key', ''));
        $templateId = (int) Settings::get('smsir_template_id', 0);
        $paramName = trim(Settings::get('smsir_param_name', 'CODE'));
        if ($apiKey === '' || $templateId <= 0) {
            return ['ok' => false, 'message' => 'تنظیمات پیامک کامل نیست.'];
        }

        $payload = [
            'mobile'     => $mobile,
            'templateId' => $templateId,
            'parameters' => [
                ['name' => $paramName, 'value' => (string) $code],
            ],
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://api.sms.ir/v1/send/verify',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
            ],
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            return ['ok' => false, 'message' => 'عدم امکان ارسال پیامک.'];
        }
        $json = json_decode($raw, true);
        $status = is_array($json) && isset($json['status']) ? (int) $json['status'] : 0;
        if ($status === 1) {
            return ['ok' => true, 'message' => 'ارسال شد.'];
        }
        $msg = is_array($json) && isset($json['message']) ? (string) $json['message'] : 'ارسال پیامک ناموفق بود.';
        return ['ok' => false, 'message' => 'ارسال کد تأیید با مشکل مواجه شد. لطفاً کمی بعد دوباره تلاش کنید.', 'detail' => $msg];
    }
}
