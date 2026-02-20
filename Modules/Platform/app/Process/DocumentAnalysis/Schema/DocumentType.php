<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema;

enum DocumentType: string
{
    case Estimate = 'estimate';
    case TechnicalSpecification = 'tech_spec';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Estimate => 'Смета',
            self::TechnicalSpecification => 'Техническое задание',
            self::Unknown => 'Неизвестный тип',
        };
    }
}
