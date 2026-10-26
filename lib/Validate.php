<?php

namespace Reactor\Kyc;

if (!defined('WHMCS')) {
    die('Access denied');
}

class Validate
{
    public static function digits($value)
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];
        $value = strtr((string) $value, $map);
        return preg_replace('/\D+/', '', $value);
    }

    public static function mobile($value)
    {
        $d = self::digits($value);
        if (strlen($d) === 12 && substr($d, 0, 2) === '98') {
            $d = '0' . substr($d, 2);
        } elseif (strlen($d) === 10 && $d[0] === '9') {
            $d = '0' . $d;
        }
        if (strlen($d) === 11 && substr($d, 0, 2) === '09') {
            return $d;
        }
        return false;
    }

    public static function nationalCode($value)
    {
        $d = self::digits($value);
        if (strlen($d) !== 10) {
            return false;
        }
        if (preg_match('/^(\d)\1{9}$/', $d)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $d[$i]) * (10 - $i);
        }
        $remainder = $sum % 11;
        $check = (int) $d[9];
        $valid = ($remainder < 2) ? ($check === $remainder) : ($check === (11 - $remainder));
        return $valid ? $d : false;
    }

    public static function cardNumber($value)
    {
        $d = self::digits($value);
        if (strlen($d) !== 16) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 16; $i++) {
            $n = (int) $d[$i];
            if ($i % 2 === 0) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
        }
        return ($sum % 10 === 0) ? $d : false;
    }

    public static function maskCard($card)
    {
        $d = self::digits($card);
        if (strlen($d) !== 16) {
            return $d;
        }
        return substr($d, 0, 6) . '******' . substr($d, 12, 4);
    }

    public static function birthDate($value)
    {
        $d = self::digits($value);
        if (strlen($d) !== 8) {
            return false;
        }
        $year = (int) substr($d, 0, 4);
        $month = (int) substr($d, 4, 2);
        $day = (int) substr($d, 6, 2);
        if ($year < 1270 || $year > 1420) {
            return false;
        }
        if ($month < 1 || $month > 12) {
            return false;
        }
        if ($day < 1 || $day > 31) {
            return false;
        }
        if ($month >= 7 && $month <= 11 && $day > 30) {
            return false;
        }
        if ($month === 12) {
            $leapRemainders = [1, 5, 9, 13, 17, 22, 26, 30];
            $isLeap = in_array($year % 33, $leapRemainders, true);
            if ($day > ($isLeap ? 30 : 29)) {
                return false;
            }
        }
        return sprintf('%04d/%02d/%02d', $year, $month, $day);
    }
}
