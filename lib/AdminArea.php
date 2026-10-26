<?php

namespace Reactor\Kyc;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access denied');
}

class AdminArea
{
    protected static $link = 'addonmodules.php?module=reactorid';

    public static function render($vars)
    {
        if (isset($vars['modulelink'])) {
            self::$link = $vars['modulelink'];
        }
        $notice = self::handleActions();

        $tab = isset($_GET['kyctab']) ? $_GET['kyctab'] : 'users';
        $view = isset($_GET['view']) ? (int) $_GET['view'] : 0;

        ob_start();
        echo self::style();
        echo $notice;
        echo self::nav($tab);

        if ($view > 0) {
            echo self::detail($view);
        } elseif ($tab === 'blacklist') {
            echo self::blacklist();
        } else {
            echo self::users();
        }
        echo '</div>';
        return ob_get_clean();
    }

    protected static function token()
    {
        return isset($_SESSION['reactorid_adm']) ? $_SESSION['reactorid_adm'] : ($_SESSION['reactorid_adm'] = bin2hex(random_bytes(12)));
    }

    protected static function checkToken()
    {
        $t = isset($_SESSION['reactorid_adm']) ? $_SESSION['reactorid_adm'] : '';
        $s = isset($_POST['t']) ? $_POST['t'] : '';
        return $t !== '' && hash_equals($t, $s);
    }

    protected static function handleActions()
    {
        if (empty($_POST['kycact'])) {
            return '';
        }
        if (!self::checkToken()) {
            return self::alert('نشست نامعتبر است.', 'danger');
        }
        $act = $_POST['kycact'];
        $adminId = (int) (isset($_SESSION['adminid']) ? $_SESSION['adminid'] : 0);

        if ($act === 'forceverify') {
            $uid = (int) $_POST['userid'];
            Verification::ensure($uid);
            Verification::update($uid, [
                'status' => Verification::STATUS_VERIFIED,
                'rejected' => 0,
                'reject_reason' => null,
                'identity_matched' => 1,
                'shahkar_matched' => 1,
                'card_matched' => 1,
                'verified_at' => date('Y-m-d H:i:s'),
            ]);
            Verification::log($uid, 'admin', 'force_verify', 'admin #' . $adminId);
            return self::alert('حساب به صورت دستی تأیید شد.', 'success');
        }
        if ($act === 'revoke') {
            $uid = (int) $_POST['userid'];
            Verification::update($uid, [
                'status' => Verification::STATUS_REJECTED,
                'rejected' => 1,
                'reject_reason' => mb_substr((string) $_POST['reason'], 0, 255),
                'verified_at' => null,
            ]);
            Verification::log($uid, 'admin', 'revoke', 'admin #' . $adminId);
            return self::alert('احراز هویت ابطال شد.', 'info');
        }
        if ($act === 'reset') {
            $uid = (int) $_POST['userid'];
            Verification::update($uid, [
                'status' => Verification::STATUS_NEW,
                'rejected' => 0,
                'reject_reason' => null,
                'mobile_verified_at' => null,
                'identity_matched' => 0,
                'shahkar_matched' => 0,
                'card_matched' => 0,
                'verified_at' => null,
            ]);
            Verification::log($uid, 'admin', 'reset', 'admin #' . $adminId);
            return self::alert('وضعیت احراز بازنشانی شد.', 'info');
        }
        if ($act === 'blacklist_add') {
            $type = in_array($_POST['type'], ['national_code', 'card', 'mobile'], true) ? $_POST['type'] : 'national_code';
            $value = Validate::digits($_POST['value']);
            if ($value === '') {
                return self::alert('مقدار نامعتبر است.', 'danger');
            }
            Capsule::table(Verification::T_BLACKLIST)->insert([
                'type' => $type,
                'value_hash' => Verification::hash($value),
                'value_label' => self::maskValue($type, $value),
                'reason' => mb_substr((string) $_POST['reason'], 0, 255),
                'created_by' => $adminId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return self::alert('به لیست سیاه افزوده شد.', 'success');
        }
        if ($act === 'blacklist_del') {
            Capsule::table(Verification::T_BLACKLIST)->where('id', (int) $_POST['id'])->delete();
            return self::alert('از لیست سیاه حذف شد.', 'info');
        }
        return '';
    }

    protected static function users()
    {
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $status = isset($_GET['status']) ? $_GET['status'] : '';
        $page = max(1, (int) (isset($_GET['p']) ? $_GET['p'] : 1));
        $per = 25;

        $query = Capsule::table(Verification::T_RECORDS . ' as r')
            ->leftJoin('tblclients as c', 'c.id', '=', 'r.userid');
        if ($q !== '') {
            $qd = Validate::digits($q);
            $query->where(function ($w) use ($q, $qd) {
                $w->where('r.national_code', $qd)
                  ->orWhere('r.mobile', 'like', '%' . $qd . '%')
                  ->orWhere('r.first_name', 'like', '%' . $q . '%')
                  ->orWhere('r.last_name', 'like', '%' . $q . '%')
                  ->orWhere('c.email', 'like', '%' . $q . '%');
            });
        }
        if ($status !== '') {
            $query->where('r.status', $status);
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('r.updated_at', 'desc')
            ->forPage($page, $per)
            ->get(['r.*', 'c.firstname as acc_first', 'c.lastname as acc_last', 'c.email as acc_email']);

        $h = '<form method="get" class="form-inline" style="margin:14px 0">'
            . '<input type="hidden" name="module" value="reactorid">'
            . '<input type="text" name="q" class="form-control" placeholder="نام، کد ملی، موبایل، ایمیل" value="' . htmlspecialchars($q) . '" style="width:260px">'
            . ' <select name="status" class="form-control">' . self::statusOptions($status) . '</select> '
            . '<button class="btn btn-primary">جستجو</button></form>';

        $h .= '<table class="table table-striped table-condensed"><thead><tr>'
            . '<th>#</th><th>نام حساب</th><th>کد ملی</th><th>موبایل</th><th>کارت</th><th>وضعیت</th><th>تاریخ</th><th></th>'
            . '</tr></thead><tbody>';
        if (count($rows) === 0) {
            $h .= '<tr><td colspan="8" style="text-align:center;color:#999;padding:20px">رکوردی یافت نشد.</td></tr>';
        }
        foreach ($rows as $r) {
            $accName = trim(($r->acc_first ?? '') . ' ' . ($r->acc_last ?? ''));
            $h .= '<tr>'
                . '<td><a href="clientssummary.php?userid=' . (int) $r->userid . '" target="_blank">' . (int) $r->userid . '</a></td>'
                . '<td>' . htmlspecialchars($accName) . '<br><small style="color:#999">' . htmlspecialchars($r->acc_email ?? '') . '</small></td>'
                . '<td><bdi dir="ltr">' . htmlspecialchars($r->national_code ?: '-') . '</bdi></td>'
                . '<td><bdi dir="ltr">' . htmlspecialchars($r->mobile ?: '-') . '</bdi></td>'
                . '<td>' . ($r->card_bank ? htmlspecialchars($r->card_bank) . ' ' : '') . '<bdi dir="ltr">' . htmlspecialchars(self::fullCard(isset($r->card_number_enc) ? $r->card_number_enc : '', $r->card_mask ?: '')) . '</bdi></td>'
                . '<td>' . self::badge($r) . '</td>'
                . '<td><small>' . htmlspecialchars($r->updated_at ?: '') . '</small></td>'
                . '<td><a class="btn btn-xs btn-default" href="' . self::$link . '&view=' . (int) $r->userid . '">جزئیات</a></td>'
                . '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= self::pager($total, $page, $per, $q, $status);
        return $h;
    }

    protected static function detail($uid)
    {
        $r = Verification::get($uid);
        if (!$r) {
            return self::alert('رکوردی برای این کاربر وجود ندارد.', 'warning') . '<a href="' . self::$link . '">بازگشت</a>';
        }
        $client = Capsule::table('tblclients')->where('id', (int) $uid)->first();
        $logs = Capsule::table(Verification::T_LOGS)->where('userid', (int) $uid)->orderBy('id', 'desc')->limit(30)->get();
        $t = self::token();

        $row = function ($label, $val, $ltr = false) {
            $val = ($val !== '' && $val !== null) ? (string) $val : '-';
            $cell = $ltr ? '<bdi dir="ltr">' . htmlspecialchars($val) . '</bdi>' : htmlspecialchars($val);
            return '<tr><td style="width:180px;color:#888">' . $label . '</td><td><b>' . $cell . '</b></td></tr>';
        };
        $rowRaw = function ($label, $html) {
            return '<tr><td style="width:180px;color:#888">' . $label . '</td><td><b>' . $html . '</b></td></tr>';
        };
        $cardNum = self::fullCard($r['card_number_enc'] ?? '', $r['card_mask'] ?? '');
        $cardHtml = ($r['card_bank'] ? htmlspecialchars($r['card_bank']) . ' - ' : '') . '<bdi dir="ltr">' . htmlspecialchars($cardNum) . '</bdi>';
        $h = '<a href="' . self::$link . '" class="btn btn-default btn-sm" style="margin:12px 0">&rarr; بازگشت به لیست</a>';
        $h .= '<div class="row"><div class="col-md-7"><div class="panel panel-default"><div class="panel-heading"><b>اطلاعات احراز</b> ' . self::badge((object) $r) . '</div><div class="panel-body"><table class="table table-condensed">';
        $h .= $row('کاربر', ($client ? trim($client->firstname . ' ' . $client->lastname) . ' (#' . $uid . ')' : '#' . $uid));
        $h .= $row('ایمیل حساب', $client ? $client->email : '');
        $h .= $row('کد ملی', $r['national_code'] ?? '', true);
        $h .= $row('تاریخ تولد', $r['birth_date'] ?? '', true);
        $h .= $row('موبایل', $r['mobile'] ?? '', true);
        $h .= $rowRaw('کارت بانکی', $cardHtml);
        $h .= $row('شاهکار (موبایل و کد ملی)', ((int) $r['shahkar_matched'] === 1 ? 'تأیید' : 'ناقص'));
        $h .= $row('تطابق کارت و کد ملی', ((int) $r['card_matched'] === 1 ? 'تأیید' : 'ناقص'));
        $h .= $row('تأیید نهایی', $r['verified_at'] ?? '', true);
        if ((int) $r['rejected'] === 1) {
            $h .= $row('دلیل ابطال', $r['reject_reason'] ?? '');
        }
        $h .= '</table></div></div></div>';

        $h .= '<div class="col-md-5"><div class="panel panel-default"><div class="panel-heading"><b>عملیات</b></div><div class="panel-body">';
        $h .= self::actionForm($uid, 'forceverify', 'تأیید دستی', 'btn-success', $t);
        $h .= self::actionForm($uid, 'reset', 'بازنشانی مراحل', 'btn-warning', $t);
        $h .= '<form method="post" style="margin-bottom:10px"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="kycact" value="revoke"><input type="hidden" name="userid" value="' . (int) $uid . '"><input type="text" name="reason" class="form-control input-sm" placeholder="دلیل ابطال" style="margin-bottom:6px"><button class="btn btn-danger btn-sm btn-block">ابطال احراز</button></form>';
        $h .= '<hr><b style="font-size:12px">افزودن به لیست سیاه</b>';
        $h .= self::blInline('national_code', $r['national_code'] ?? '', 'کد ملی', $t);
        $h .= self::blInline('mobile', $r['mobile'] ?? '', 'موبایل', $t);
        $h .= '</div></div></div></div>';

        $h .= '<div class="panel panel-default"><div class="panel-heading"><b>گزارش رویدادها</b></div><div class="panel-body"><table class="table table-condensed table-striped"><thead><tr><th>زمان</th><th>مرحله</th><th>وضعیت</th><th>پیام</th><th>IP</th></tr></thead><tbody>';
        foreach ($logs as $lg) {
            $h .= '<tr><td><small>' . htmlspecialchars($lg->created_at) . '</small></td><td>' . htmlspecialchars($lg->step) . '</td><td>' . htmlspecialchars($lg->status) . '</td><td><small>' . htmlspecialchars($lg->message) . '</small></td><td><small>' . htmlspecialchars($lg->ip) . '</small></td></tr>';
        }
        if (count($logs) === 0) {
            $h .= '<tr><td colspan="5" style="text-align:center;color:#999">گزارشی نیست.</td></tr>';
        }
        $h .= '</tbody></table></div></div>';
        return $h;
    }

    protected static function blInline($type, $value, $label, $t)
    {
        if ($value === '') {
            return '';
        }
        return '<form method="post" style="margin:6px 0"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="kycact" value="blacklist_add"><input type="hidden" name="type" value="' . $type . '"><input type="hidden" name="value" value="' . htmlspecialchars($value) . '"><input type="hidden" name="reason" value="از صفحه کاربر"><button class="btn btn-xs btn-default btn-block">مسدودسازی ' . $label . ': ' . htmlspecialchars($value) . '</button></form>';
    }

    protected static function actionForm($uid, $act, $label, $cls, $t)
    {
        return '<form method="post" style="margin-bottom:8px"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="kycact" value="' . $act . '"><input type="hidden" name="userid" value="' . (int) $uid . '"><button class="btn ' . $cls . ' btn-sm btn-block">' . $label . '</button></form>';
    }

    protected static function blacklist()
    {
        $t = self::token();
        $rows = Capsule::table(Verification::T_BLACKLIST)->orderBy('id', 'desc')->get();
        $types = ['national_code' => 'کد ملی', 'card' => 'کارت', 'mobile' => 'موبایل'];

        $h = '<div class="panel panel-default"><div class="panel-heading"><b>افزودن به لیست سیاه</b></div><div class="panel-body">';
        $h .= '<form method="post" class="form-inline"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="kycact" value="blacklist_add">';
        $h .= '<select name="type" class="form-control"><option value="national_code">کد ملی</option><option value="mobile">موبایل</option><option value="card">کارت</option></select> ';
        $h .= '<input type="text" name="value" class="form-control" placeholder="مقدار" style="width:200px"> ';
        $h .= '<input type="text" name="reason" class="form-control" placeholder="دلیل" style="width:220px"> ';
        $h .= '<button class="btn btn-danger">افزودن</button></form></div></div>';

        $h .= '<table class="table table-striped"><thead><tr><th>نوع</th><th>مقدار</th><th>دلیل</th><th>تاریخ</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr><td>' . ($types[$r->type] ?? $r->type) . '</td><td>' . htmlspecialchars($r->value_label) . '</td><td>' . htmlspecialchars($r->reason) . '</td><td><small>' . htmlspecialchars($r->created_at) . '</small></td>'
                . '<td><form method="post" onsubmit="return confirm(\'حذف شود؟\')"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="kycact" value="blacklist_del"><input type="hidden" name="id" value="' . (int) $r->id . '"><button class="btn btn-xs btn-default">حذف</button></form></td></tr>';
        }
        if (count($rows) === 0) {
            $h .= '<tr><td colspan="5" style="text-align:center;color:#999">لیست سیاه خالی است.</td></tr>';
        }
        $h .= '</tbody></table>';
        return $h;
    }

    protected static function nav($tab)
    {
        $item = function ($key, $label, $active) {
            return '<li' . ($active ? ' class="active"' : '') . '><a href="' . self::$link . '&kyctab=' . $key . '">' . $label . '</a></li>';
        };
        $warn = Settings::configured() ? '' : self::alert('توکن زحل یا کلید پیامک تنظیم نشده است. از بخش تنظیمات افزونه آن‌ها را وارد کنید.', 'warning');
        return $warn . '<ul class="nav nav-tabs" style="margin-bottom:6px">'
            . $item('users', 'کاربران', $tab === 'users')
            . $item('blacklist', 'لیست سیاه', $tab === 'blacklist')
            . '</ul>';
    }

    protected static function statusOptions($sel)
    {
        $opts = [
            '' => 'همه وضعیت‌ها',
            Verification::STATUS_VERIFIED => 'احراز شده',
            Verification::STATUS_MOBILE => 'موبایل تأیید',
            Verification::STATUS_IDENTITY => 'هویت تأیید',
            Verification::STATUS_NEW => 'جدید',
            Verification::STATUS_REJECTED => 'ابطال‌شده',
        ];
        $h = '';
        foreach ($opts as $k => $v) {
            $h .= '<option value="' . $k . '"' . ($sel === $k ? ' selected' : '') . '>' . $v . '</option>';
        }
        return $h;
    }

    protected static function badge($r)
    {
        if ((int) $r->rejected === 1) {
            return '<span class="label label-danger">ابطال‌شده</span>';
        }
        switch ($r->status) {
            case Verification::STATUS_VERIFIED:
                return '<span class="label label-success">احراز شده</span>';
            case Verification::STATUS_IDENTITY:
                return '<span class="label label-info">هویت تأیید</span>';
            case Verification::STATUS_MOBILE:
                return '<span class="label label-warning">موبایل تأیید</span>';
            default:
                return '<span class="label label-default">جدید</span>';
        }
    }

    protected static function pager($total, $page, $per, $q, $status)
    {
        $pages = (int) ceil($total / $per);
        if ($pages <= 1) {
            return '<div style="color:#999;font-size:12px">مجموع: ' . (int) $total . '</div>';
        }
        $base = self::$link . '&q=' . urlencode($q) . '&status=' . urlencode($status) . '&p=';
        $h = '<ul class="pagination pagination-sm">';
        for ($i = 1; $i <= $pages; $i++) {
            $h .= '<li' . ($i === $page ? ' class="active"' : '') . '><a href="' . $base . $i . '">' . $i . '</a></li>';
        }
        return $h . '</ul>';
    }

    protected static function fullCard($enc, $mask)
    {
        $pan = $enc !== '' ? Crypto::decrypt($enc) : '';
        if (preg_match('/^\d{16}$/', $pan)) {
            return trim(chunk_split($pan, 4, ' '));
        }
        return $mask !== '' ? $mask : '-';
    }

    protected static function maskValue($type, $value)
    {
        if ($type === 'card' && strlen($value) === 16) {
            return substr($value, 0, 6) . '***' . substr($value, 12, 4);
        }
        if ($type === 'national_code' && strlen($value) === 10) {
            return substr($value, 0, 3) . '****' . substr($value, 7);
        }
        if ($type === 'mobile' && strlen($value) === 11) {
            return substr($value, 0, 4) . '***' . substr($value, 7);
        }
        return $value;
    }

    protected static function alert($text, $type)
    {
        return '<div class="alert alert-' . $type . '">' . $text . '</div>';
    }

    protected static function style()
    {
        return '<style>#reactorid-admin .label{font-size:11px}.afk-admin-wrap{direction:rtl}</style><div class="afk-admin-wrap">';
    }
}
