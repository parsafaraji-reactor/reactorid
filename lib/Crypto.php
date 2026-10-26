<?php

namespace Reactor\Kyc;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access denied');
}

class Crypto
{
    const CIPHER = 'AES-256-CBC';

    protected static $key = null;

    public static function key()
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $k = (string) Settings::get('enc_key', '');
        if (strlen($k) < 32) {
            $k = bin2hex(random_bytes(24));
            try {
                $exists = Capsule::table('tbladdonmodules')
                    ->where('module', 'reactorid')->where('setting', 'enc_key')->exists();
                if ($exists) {
                    Capsule::table('tbladdonmodules')
                        ->where('module', 'reactorid')->where('setting', 'enc_key')
                        ->update(['value' => $k]);
                } else {
                    Capsule::table('tbladdonmodules')->insert([
                        'module' => 'reactorid', 'setting' => 'enc_key', 'value' => $k,
                    ]);
                }
            } catch (\Throwable $e) {
                // fall back to in-memory key for this request
            }
        }
        self::$key = hash('sha256', $k, true);
        return self::$key;
    }

    public static function encrypt($plain)
    {
        $plain = (string) $plain;
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(16);
        $ct = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        if ($ct === false) {
            return '';
        }
        return base64_encode($iv . $ct);
    }

    public static function decrypt($enc)
    {
        $enc = (string) $enc;
        if ($enc === '') {
            return '';
        }
        $raw = base64_decode($enc, true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $ct = substr($raw, 16);
        $pt = openssl_decrypt($ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        return $pt === false ? '' : $pt;
    }
}
