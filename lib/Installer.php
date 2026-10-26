<?php

namespace Reactor\Kyc;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Access denied');
}

class Installer
{
    public static function install()
    {
        $schema = Capsule::schema();

        if (!Capsule::table('tbladdonmodules')->where('module', 'reactorid')->where('setting', 'enc_key')->exists()) {
            Capsule::table('tbladdonmodules')->insert([
                'module'  => 'reactorid',
                'setting' => 'enc_key',
                'value'   => bin2hex(random_bytes(24)),
            ]);
        }

        if (!$schema->hasTable(Verification::T_RECORDS)) {
            $schema->create(Verification::T_RECORDS, function ($t) {
                $t->increments('id');
                $t->integer('userid')->unsigned()->unique();
                $t->string('status', 20)->default('new');
                $t->string('mobile', 15)->nullable();
                $t->dateTime('mobile_verified_at')->nullable();
                $t->string('national_code', 10)->nullable();
                $t->string('birth_date', 10)->nullable();
                $t->string('first_name', 100)->nullable();
                $t->string('last_name', 100)->nullable();
                $t->string('father_name', 100)->nullable();
                $t->string('card_mask', 20)->nullable();
                $t->string('card_hash', 64)->nullable();
                $t->string('card_sha256', 64)->nullable();
                $t->string('card_number_enc', 255)->nullable();
                $t->string('card_bank', 60)->nullable();
                $t->tinyInteger('identity_matched')->default(0);
                $t->tinyInteger('shahkar_matched')->default(0);
                $t->tinyInteger('card_matched')->default(0);
                $t->tinyInteger('name_match')->nullable();
                $t->tinyInteger('rejected')->default(0);
                $t->string('reject_reason', 255)->nullable();
                $t->dateTime('verified_at')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index('status');
                $t->index('national_code');
            });
        }

        if (!$schema->hasTable(Verification::T_OTPS)) {
            $schema->create(Verification::T_OTPS, function ($t) {
                $t->increments('id');
                $t->integer('userid')->unsigned()->unique();
                $t->string('mobile', 15);
                $t->string('code_hash', 255);
                $t->integer('attempts')->default(0);
                $t->dateTime('expires_at')->nullable();
                $t->dateTime('last_sent_at')->nullable();
                $t->dateTime('created_at')->nullable();
            });
        }

        if (!$schema->hasTable(Verification::T_LOGS)) {
            $schema->create(Verification::T_LOGS, function ($t) {
                $t->increments('id');
                $t->integer('userid')->unsigned();
                $t->string('step', 20);
                $t->string('status', 30);
                $t->string('message', 500)->nullable();
                $t->string('ip', 45)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->index('userid');
            });
        }

        if ($schema->hasTable(Verification::T_RECORDS) && !$schema->hasColumn(Verification::T_RECORDS, 'card_sha256')) {
            $schema->table(Verification::T_RECORDS, function ($t) {
                $t->string('card_sha256', 64)->nullable()->after('card_hash');
            });
        }

        if ($schema->hasTable(Verification::T_RECORDS) && !$schema->hasColumn(Verification::T_RECORDS, 'card_number_enc')) {
            $schema->table(Verification::T_RECORDS, function ($t) {
                $t->string('card_number_enc', 255)->nullable()->after('card_sha256');
            });
        }

        if (!$schema->hasTable(Verification::T_BLACKLIST)) {
            $schema->create(Verification::T_BLACKLIST, function ($t) {
                $t->increments('id');
                $t->string('type', 20);
                $t->string('value_hash', 64);
                $t->string('value_label', 40)->nullable();
                $t->string('reason', 255)->nullable();
                $t->integer('created_by')->unsigned()->nullable();
                $t->dateTime('created_at')->nullable();
                $t->index(['type', 'value_hash']);
            });
        }
    }
}
