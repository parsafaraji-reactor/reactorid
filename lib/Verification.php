<?php

namespace Reactor\Kyc;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access denied');
}

class Verification
{
    const T_RECORDS   = 'mod_reactorid_records';
    const T_OTPS      = 'mod_reactorid_otps';
    const T_LOGS      = 'mod_reactorid_logs';
    const T_BLACKLIST = 'mod_reactorid_blacklist';

    const STATUS_NEW      = 'new';
    const STATUS_MOBILE   = 'mobile';
    const STATUS_IDENTITY = 'identity';
    const STATUS_VERIFIED = 'verified';
    const STATUS_REJECTED = 'rejected';

    public static function get($userId)
    {
        $row = Capsule::table(self::T_RECORDS)->where('userid', (int) $userId)->first();
        return $row ? (array) $row : null;
    }

    public static function ensure($userId)
    {
        $userId = (int) $userId;
        $row = self::get($userId);
        if ($row) {
            return $row;
        }
        $now = date('Y-m-d H:i:s');
        Capsule::table(self::T_RECORDS)->insert([
            'userid'     => $userId,
            'status'     => self::STATUS_NEW,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return self::get($userId);
    }

    public static function update($userId, array $fields)
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table(self::T_RECORDS)->where('userid', (int) $userId)->update($fields);
        return self::get($userId);
    }

    public static function isVerified($userId)
    {
        $row = self::get($userId);
        return $row && $row['status'] === self::STATUS_VERIFIED && (int) $row['rejected'] === 0;
    }

    /**
     * A verified record that still has no stored card while card locking is on
     * (an account verified before card storage existed). Such a user must
     * register their card once before purchasing.
     */
    public static function needsCardRegistration($userId)
    {
        $row = is_array($userId) ? $userId : self::get($userId);
        if (!$row || $row['status'] !== self::STATUS_VERIFIED || (int) $row['rejected'] === 1) {
            return false;
        }
        return Settings::isOn('enforce_payment_card') && empty($row['card_number_enc']);
    }

    /**
     * Ready to purchase: verified and (card stored or card-lock disabled).
     */
    public static function isReady($userId)
    {
        $row = self::get($userId);
        if (!$row || $row['status'] !== self::STATUS_VERIFIED || (int) $row['rejected'] === 1) {
            return false;
        }
        return !self::needsCardRegistration($row);
    }

    /**
     * Number of recent log rows for a user, optionally filtered by step and
     * status, within the last $sinceSeconds. Used for rate limiting paid
     * inquiries (Zohal) and SMS sending.
     */
    public static function countRecentLogs($userId, array $steps, array $statuses, $sinceSeconds)
    {
        try {
            $q = Capsule::table(self::T_LOGS)
                ->where('userid', (int) $userId)
                ->whereIn('step', $steps)
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - (int) $sinceSeconds));
            if ($statuses) {
                $q->whereIn('status', $statuses);
            }
            return (int) $q->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function rateLimited($userId, array $steps, $max, $sinceSeconds = 3600, array $statuses = [])
    {
        return self::countRecentLogs($userId, $steps, $statuses, $sinceSeconds) >= (int) $max;
    }

    public static function currentStep($row)
    {
        if (!$row) {
            return 1;
        }
        if ((int) $row['rejected'] === 1) {
            return 1;
        }
        if (empty($row['mobile_verified_at'])) {
            return 1;
        }
        if ((int) $row['identity_matched'] !== 1 || (int) $row['shahkar_matched'] !== 1) {
            return 2;
        }
        if ((int) $row['card_matched'] !== 1) {
            return 3;
        }
        return 4;
    }

    public static function recompute($userId)
    {
        $row = self::get($userId);
        if (!$row) {
            return null;
        }
        if ((int) $row['rejected'] === 1) {
            return $row;
        }
        $status = self::STATUS_NEW;
        if (!empty($row['mobile_verified_at'])) {
            $status = self::STATUS_MOBILE;
        }
        if ($status === self::STATUS_MOBILE && (int) $row['identity_matched'] === 1 && (int) $row['shahkar_matched'] === 1) {
            $status = self::STATUS_IDENTITY;
        }
        if ($status === self::STATUS_IDENTITY && (int) $row['card_matched'] === 1) {
            $status = self::STATUS_VERIFIED;
        }
        $fields = ['status' => $status];
        if ($status === self::STATUS_VERIFIED && empty($row['verified_at'])) {
            $fields['verified_at'] = date('Y-m-d H:i:s');
        }
        return self::update($userId, $fields);
    }

    public static function verifiedCard($userId)
    {
        $row = self::get($userId);
        if (!$row || $row['status'] !== self::STATUS_VERIFIED || (int) $row['rejected'] === 1) {
            return null;
        }
        return [
            'mask'   => isset($row['card_mask']) ? $row['card_mask'] : '',
            'sha256' => isset($row['card_sha256']) ? $row['card_sha256'] : '',
        ];
    }

    /**
     * The full card number (PAN) the client verified during KYC, decrypted,
     * for use as ZarinPal metadata.card_pan in the payment request. Empty when
     * the client is not verified or the card was stored before encryption existed.
     */
    public static function verifiedCardNumber($userId)
    {
        $row = self::get($userId);
        if (!$row || $row['status'] !== self::STATUS_VERIFIED || (int) $row['rejected'] === 1) {
            return '';
        }
        if (empty($row['card_number_enc'])) {
            return '';
        }
        return Crypto::decrypt($row['card_number_enc']);
    }

    /**
     * Decide whether the card used at the payment gateway is the same card the
     * user verified during KYC. $gatewayPan is the masked PAN returned by the
     * gateway (e.g. ZarinPal card_pan "603799******1234"); $gatewayHash is the
     * gateway card hash if available (e.g. ZarinPal card_hash, SHA256 of PAN).
     * Returns ['hasCard' => bool, 'match' => bool].
     */
    public static function paymentCardMatches($userId, $gatewayPan, $gatewayHash = '')
    {
        $card = self::verifiedCard($userId);
        if (!$card || $card['mask'] === '') {
            return ['hasCard' => false, 'match' => false];
        }

        if ($card['sha256'] !== '' && trim((string) $gatewayHash) !== '') {
            if (hash_equals(strtolower($card['sha256']), strtolower(trim((string) $gatewayHash)))) {
                return ['hasCard' => true, 'match' => true];
            }
        }

        $a = Validate::digits($card['mask']);
        $b = Validate::digits($gatewayPan);
        if (strlen($a) === 10 && strlen($b) >= 10) {
            $bKey = substr($b, 0, 6) . substr($b, -4);
            return ['hasCard' => true, 'match' => ($a === $bKey)];
        }
        if (strlen($a) === 10 && strlen($b) === 4) {
            return ['hasCard' => true, 'match' => (substr($a, -4) === $b)];
        }
        return ['hasCard' => true, 'match' => false];
    }

    public static function isBlacklisted($type, $value)
    {
        $hash = self::hash($value);
        return Capsule::table(self::T_BLACKLIST)
            ->where('type', $type)
            ->where('value_hash', $hash)
            ->exists();
    }

    public static function hash($value)
    {
        return hash('sha256', Validate::digits($value) . '|reactorid');
    }

    public static function nationalCodeTaken($nationalCode, $exceptUserId = 0)
    {
        return Capsule::table(self::T_RECORDS)
            ->where('national_code', Validate::digits($nationalCode))
            ->where('status', self::STATUS_VERIFIED)
            ->where('userid', '!=', (int) $exceptUserId)
            ->exists();
    }

    public static function log($userId, $step, $status, $message)
    {
        try {
            Capsule::table(self::T_LOGS)->insert([
                'userid'     => (int) $userId,
                'step'       => $step,
                'status'     => $status,
                'message'    => mb_substr((string) $message, 0, 500),
                'ip'         => self::ip(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            
        }
    }

    public static function ip()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        return mb_substr($ip, 0, 45);
    }
}
