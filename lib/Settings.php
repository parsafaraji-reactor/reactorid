<?php

namespace Reactor\Kyc;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access denied');
}

class Settings
{
    protected static $cache = null;

    protected static $defaults = [
        'zohal_token'        => '',
        'smsir_api_key'      => '',
        'smsir_template_id'  => '',
        'smsir_param_name'   => 'CODE',
        'enforce_checkout'      => 'on',
        'block_guest'           => 'on',
        'enforce_payment_card'  => 'on',
    ];

    public static function all()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $values = self::$defaults;
        try {
            $rows = Capsule::table('tbladdonmodules')
                ->where('module', 'reactorid')
                ->get();
            foreach ($rows as $row) {
                $values[$row->setting] = $row->value;
            }
        } catch (\Throwable $e) {
            
        }
        self::$cache = $values;
        return $values;
    }

    public static function get($key, $fallback = null)
    {
        $all = self::all();
        if (array_key_exists($key, $all) && $all[$key] !== '') {
            return $all[$key];
        }
        if ($fallback !== null) {
            return $fallback;
        }
        return isset(self::$defaults[$key]) ? self::$defaults[$key] : null;
    }

    public static function isOn($key)
    {
        return self::get($key) === 'on';
    }

    public static function configured()
    {
        return self::get('zohal_token') !== ''
            && self::get('smsir_api_key') !== ''
            && (int) self::get('smsir_template_id', 0) > 0;
    }
}
