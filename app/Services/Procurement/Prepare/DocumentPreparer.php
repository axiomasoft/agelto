<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Jobs\Procurement\PrepareDocumentJob;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Выбор и запуск стратегии подготовки (`config('ai.prepare.strategies')`, порядок важен —
 * `PassthroughStrategy` всегда последняя и ловит всё остальное, канон v1
 * `Old/app/Process/DocumentPrepare/DocumentProcessor.php::preparation()`). Стратегия сама решает
 * исход (успех/`unprocessable`); {@see PrepareDocumentJob} переводит
 * документ в `prepared`, если стратегия не перевела его в терминальный `unprocessable`.
 */
final readonly class DocumentPreparer
{
    public function __construct(
        private Container $container,
    ) {}

    public function prepare(Document $document, DocumentVersion $version): void
    {
        $this->resolveStrategy($version)->process($document, $version);
    }

    private function resolveStrategy(DocumentVersion $version): PreparationStrategy
    {
        foreach ((array) config('ai.prepare.strategies', []) as $class) {
            /** @var PreparationStrategy $strategy */
            $strategy = $this->container->make($class);

            if ($strategy->canProcess($version)) {
                return $strategy;
            }
        }

        throw new RuntimeException("Нет стратегии подготовки для расширения «{$version->extension}».");
    }
}
