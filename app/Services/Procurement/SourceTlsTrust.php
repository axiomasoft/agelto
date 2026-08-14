<?php

declare(strict_types=1);

namespace App\Services\Procurement;

/**
 * Узкое доверие TLS по хосту (`config('crawler.tls.bundles')`, P4.12, D125): единственный
 * носитель правила «какой хост каким бандлом» — читать конфиг напрямую из call-site'а запрещено.
 * Отсутствие хоста в карте = системный трест (пустой результат), не отказ.
 */
final readonly class SourceTlsTrust
{
    /**
     * @return array{verify?: string}
     */
    public function optionsFor(string $host): array
    {
        // Хост — доменное имя с точками (`zakupki.gov.ru`): dot-notation `config('a.b.host')`
        // читала бы его как вложенный путь, а не ключ карты — массив достаётся целиком.
        $bundles = config('crawler.tls.bundles', []);

        if (! is_array($bundles)) {
            throw new \LogicException('crawler.tls.bundles должен быть картой путей CA-бандлов.');
        }

        $bundle = $bundles[$host] ?? null;

        if ($bundle === null) {
            return [];
        }

        if (! is_string($bundle) || $bundle === '') {
            throw new \LogicException("CA-бандл для {$host} должен быть непустой строкой.");
        }

        return ['verify' => $bundle];
    }
}
