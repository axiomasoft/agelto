<?php

declare(strict_types=1);

namespace App\Services\Llm\Contracts;

/**
 * Опциональный контракт структурной проверки ответа  — у
 * `StructuredResponseValidator` в точке проверки нет типа документа, поэтому маршрутизация через
 * `DocumentAnalyzerRegistry` невозможна по построению; проверку объявляет само DTO ответа.
 * Возврат — сообщение об ошибке (retryable) или `null` (структура валидна); арифметическая сверка
 * сюда не входит — остаётся доменным уровнем.
 */
interface StructurallyValidatable
{
    public function structuralError(): ?string;
}
