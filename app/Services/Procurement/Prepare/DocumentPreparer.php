<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\DocumentStoreRepository;
use Illuminate\Contracts\Container\Container;

/**
 * ЦЕПОЧКА стратегий подготовки (D28, `config('ai.prepare.strategies')`, порядок важен —
 * `PassthroughStrategy` всегда последняя и возвращает `true`): вызывается КАЖДАЯ подошедшая по
 * `canProcess()`, пока одна не вернёт `true` или пока документ не станет терминальным. Отказ
 * стратегии (`false`) — обычный исход, а не приговор документу: лимит ИНСТРУМЕНТА подготовки
 * означает «пробуем следующую», лимит ПОТРЕБИТЕЛЯ живёт в capability-матрице модели (D26).
 *
 * `unprocessable(conversion_failed)` наступает, только когда цепочка исчерпана и ни одна
 * стратегия не отработала (D27); {@see PrepareDocumentJob} переводит документ в `prepared`,
 * если он не стал терминальным. Исключение стратегии НЕ ловится: оно пробрасывается в
 * error-блок job'а, а не маскируется под «не подошла».
 */
final readonly class DocumentPreparer
{
    public function __construct(
        private Container $container,
        private DocumentStoreRepository $documents,
    ) {}

    public function prepare(Document $document, DocumentVersion $version): void
    {
        $tried = [];

        foreach ((array) config('ai.prepare.strategies', []) as $class) {
            /** @var PreparationStrategy $strategy */
            $strategy = $this->container->make($class);

            if (! $strategy->canProcess($version)) {
                continue;
            }

            $tried[] = class_basename($class);

            if ($strategy->process($document, $version)) {
                return;
            }

            if ($document->status === DocumentStatus::Unprocessable) {
                return;
            }
        }

        if ($document->status === DocumentStatus::Unprocessable) {
            return;
        }

        $this->documents->update($document, [
            'status' => DocumentStatus::Unprocessable,
            'last_error' => 'unprocessable:'.UnprocessableReason::ConversionFailed->value
                .': ни одна стратегия не произвела представление, испробованы: '
                .($tried === [] ? '—' : implode(', ', $tried)),
        ]);
    }
}
