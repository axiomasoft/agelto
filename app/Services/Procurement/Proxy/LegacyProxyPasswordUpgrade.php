<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Models\Procurement\Crawler\Proxy;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Forward-only upgrade; all validation and writes commit together. Never logs values. */
final readonly class LegacyProxyPasswordUpgrade
{
    /** @return array{encrypted: int, plaintext: int} */
    public function run(bool $apply = false): array
    {
        return DB::transaction(function () use ($apply): array {
            $counts = ['encrypted' => 0, 'plaintext' => 0];
            $rows = DB::table((new Proxy)->getTable())->whereNotNull('password')->orderBy('id')->lockForUpdate()->get(['id', 'password']);
            foreach ($rows as $row) {
                $password = $row->password;
                if (! is_string($password)) {
                    throw new RuntimeException('Unexpected proxy password storage type.');
                }
                try {
                    Crypt::decryptString($password);
                    $counts['encrypted']++;

                    continue;
                } catch (DecryptException) {
                    // Laravel ciphertext is base64 JSON. Damaged/foreign-key envelopes and
                    // opaque base64 values need explicit operator review, not double encryption.
                    if (str_starts_with($password, 'eyJ') || str_starts_with(ltrim($password), '{')
                        || strlen($password) >= 80) {
                        throw new RuntimeException('Proxy password envelope cannot be decrypted; check APP_KEY or restore the credential. No changes applied.');
                    }
                }
                $counts['plaintext']++;
                if ($apply) {
                    DB::table((new Proxy)->getTable())->where('id', $row->id)->update(['password' => Crypt::encryptString($password)]);
                }
            }

            return $counts;
        });
    }
}
