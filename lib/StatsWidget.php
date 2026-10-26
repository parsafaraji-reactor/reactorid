<?php

namespace Reactor\Kyc;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access denied');
}

class StatsWidget extends \WHMCS\Module\AbstractWidget
{
    protected $title = 'احراز هویت ری‌اکتور';
    protected $description = '';
    protected $weight = 60;
    protected $columns = 1;
    protected $cache = false;

    public function getData()
    {
        try {
            $verified = Capsule::table(Verification::T_RECORDS)->where('status', Verification::STATUS_VERIFIED)->count();
            $pending = Capsule::table(Verification::T_RECORDS)
                ->where('status', '!=', Verification::STATUS_VERIFIED)
                ->where('rejected', 0)->count();
            $today = Capsule::table(Verification::T_RECORDS)
                ->where('status', Verification::STATUS_VERIFIED)
                ->where('verified_at', '>=', date('Y-m-d 00:00:00'))->count();
            return ['verified' => $verified, 'pending' => $pending, 'today' => $today];
        } catch (\Throwable $e) {
            return ['verified' => 0, 'pending' => 0, 'today' => 0];
        }
    }

    public function generateOutput($data)
    {
        $link = 'addonmodules.php?module=reactorid';
        $box = function ($num, $label, $color) {
            return '<div style="flex:1;text-align:center;padding:10px">'
                . '<div style="font-size:26px;font-weight:800;color:' . $color . '">' . (int) $num . '</div>'
                . '<div style="font-size:12px;color:#888">' . $label . '</div></div>';
        };
        return '<div class="widget-content-padded"><div style="display:flex;justify-content:space-around">'
            . $box($data['verified'], 'احراز شده', '#16b364')
            . $box($data['pending'], 'در انتظار', '#f5a623')
            . $box($data['today'], 'امروز', '#2663EB')
            . '</div><div style="text-align:center;margin-top:8px"><a href="' . $link . '">مدیریت احراز هویت</a></div></div>';
    }
}
