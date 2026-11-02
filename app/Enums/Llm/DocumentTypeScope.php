<?php

declare(strict_types=1);

namespace App\Enums\Llm;

/**
 * Носитель кросс-документного назначения маршрута/промта (D16, §1.2 root/document-type-axis.md):
 * сентинел "годится для ЛЮБОГО типа документа" — именованная замена голого литерала `'any'`.
 */
enum DocumentTypeScope: string implements DocumentScope
{
    case Any = 'any';

    public function key(): string
    {
        return $this->value;
    }
}
