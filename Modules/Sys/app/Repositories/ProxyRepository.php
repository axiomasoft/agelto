<?php

namespace App\Repositories;

use Sys\Models\Proxy\Proxy;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProxyRepository
{
    /**
     * Порог ошибок, после которого прокси перестает выдаваться в getRandomActiveProxy
     */
    protected const MAX_FAILS = 5;

    /**
     * Сброс ошибок при успешном соединении
     */
    public function markSuccess(int $proxyId): void
    {
        Proxy::where('id', $proxyId)->update([
            'fails_count' => 0,
            'last_error'  => null,
            'is_active'   => true, // Можно принудительно активировать, если был выключен
        ]);
    }

    /**
     * Фиксация ошибки
     */
    public function markFailed(int $proxyId, Throwable $e): void
    {
        $proxy = Proxy::find($proxyId);
        if (!$proxy) return;

        $newFailsCount = $proxy->fails_count + 1;
        $errorMessage = sprintf(
            '[%s] %s (File: %s, Line: %d)',
            get_class($e),
            $e->getMessage(),
            basename($e->getFile()),
            $e->getLine()
        );

        $proxy->update([
            'fails_count' => $newFailsCount,
            'last_error'  => mb_substr($errorMessage, 0, 1000), // Храним до 1000 символов
        ]);

        // Логируем критическое состояние
        if ($newFailsCount >= self::MAX_FAILS) {
            Log::warning("Proxy {$proxy->ip_address} reached MAX_FAILS. Error: {$e->getMessage()}");

            // Если нужно, можно деактивировать автоматически:
            $proxy->update(['is_active' => false]);
        }
    }

    /**
     * Получение "здорового" прокси
     */
    public function getRandomActiveProxy(string $serviceCode): ?Proxy
    {
        return Proxy::query()
            ->whereHas('service', fn($q) => $q->where('code', $serviceCode))
            ->where('is_active', true)
            ->where('fails_count', '<', self::MAX_FAILS)
            ->inRandomOrder()
            ->first();
    }
}
