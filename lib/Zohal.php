<?php

namespace Reactor\Kyc;

if (!defined('WHMCS')) {
    die('Access denied');
}

class Zohal
{
    const BASE_URL     = 'https://service.zohal.io';
    const EP_SHAHKAR   = '/api/v0/services/inquiry/shahkar';
    const EP_CARD_NID  = '/api/v0/services/inquiry/check_card_with_national_code';

    public static function shahkar($mobile, $nationalCode)
    {
        return self::call(self::EP_SHAHKAR, [
            'mobile'        => $mobile,
            'national_code' => $nationalCode,
        ]);
    }

    public static function checkCard($nationalCode, $birthDate, $cardNumber)
    {
        
        
        $birthDate = preg_replace('/\D+/', '', (string) $birthDate);
        return self::call(self::EP_CARD_NID, [
            'national_code' => $nationalCode,
            'birth_date'    => $birthDate,
            'card_number'   => $cardNumber,
        ]);
    }

    protected static function call($endpoint, array $payload)
    {
        $token = trim(Settings::get('zohal_token', ''));
        if ($token === '') {
            return self::fail('توکن سرویس احراز هویت تنظیم نشده است.', 'NO_TOKEN');
        }
        $url = self::BASE_URL . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return self::fail('عدم امکان اتصال به سرویس احراز هویت.', 'CONNECTION', $http, $error);
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return self::fail('پاسخ نامعتبر از سرویس احراز هویت.', 'BAD_RESPONSE', $http);
        }

        $body = [];
        if (isset($json['response_body']) && is_array($json['response_body'])) {
            $body = $json['response_body'];
        } elseif (isset($json['reponse_body']) && is_array($json['reponse_body'])) {
            
            $body = $json['reponse_body'];
        }
        $result = isset($json['result']) ? (int) $json['result'] : 0;
        $data = isset($body['data']) ? $body['data'] : null;
        $message = isset($body['message']) ? $body['message'] : '';
        $errorCode = isset($body['error_code']) ? $body['error_code'] : (isset($body['error']) ? $body['error'] : null);

        $matched = null;
        if (is_array($data) && array_key_exists('matched', $data)) {
            $matched = (bool) $data['matched'];
        }

        $ok = ($http >= 200 && $http < 300 && $result === 1 && empty($errorCode));

        return [
            'ok'         => $ok,
            'matched'    => $matched,
            'data'       => is_array($data) ? $data : [],
            'message'    => $message,
            'error_code' => $errorCode,
            'result'     => $result,
            'http'       => $http,
        ];
    }

    protected static function fail($message, $code, $http = 0, $detail = '')
    {
        return [
            'ok'         => false,
            'matched'    => null,
            'data'       => [],
            'message'    => $message,
            'error_code' => $code,
            'result'     => 0,
            'http'       => $http,
            'detail'     => $detail,
        ];
    }
}
