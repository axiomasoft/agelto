<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Services\Procurement\Analyzers\Estimate\EstimateFieldsAuditor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;

/** Read-only audit of the five mandatory estimate-item fields over gold files or persisted estimates. */
#[Description('Измерить заполненность обязательных полей позиций сметы')]
#[Signature('estimate:fields:audit
        {--source=gold : Источник: gold или database}
        {--json : Машинный JSON вместо таблицы}')]
final class FieldsAuditCommand extends Command
{
    public function handle(EstimateFieldsAuditor $auditor = new EstimateFieldsAuditor): int
    {
        $source = $this->option('source');

        if (! is_string($source) || ! in_array($source, ['gold', 'database'], strict: true)) {
            $this->error('Источник должен быть gold или database.');

            return self::FAILURE;
        }

        try {
            $audit = $auditor->audit($source);
        } catch (JsonException $exception) {
            $this->error("Не удалось прочитать gold-корпус: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(['Тип сметы', 'Поле', 'Заполнено', 'Доля'], $auditor->tableRows($audit['tree']));

        return self::SUCCESS;
    }
}
