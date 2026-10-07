<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * `prc_proxies.password` переходит на cast `encrypted`: уже сохранённые открытые пароли
 * шифруются на месте. Значение, которое уже расшифровывается текущим ключом, не трогается —
 * повторный прогон миграции идемпотентен.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('prc_proxies')->whereNotNull('password')->orderBy('id')->lazyById()->each(function (object $proxy): void {
            $password = $proxy->password;

            if (! is_string($password) || $this->isEncrypted($password)) {
                return;
            }

            DB::table('prc_proxies')->where('id', $proxy->id)->update(['password' => Crypt::encryptString($password)]);
        });
    }

    public function down(): void
    {
        DB::table('prc_proxies')->whereNotNull('password')->orderBy('id')->lazyById()->each(function (object $proxy): void {
            $password = $proxy->password;

            if (! is_string($password) || ! $this->isEncrypted($password)) {
                return;
            }

            DB::table('prc_proxies')->where('id', $proxy->id)->update(['password' => Crypt::decryptString($password)]);
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
