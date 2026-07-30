<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement;

use App\Services\Llm\BillingReconciler;

/**
 * Файл выгрузки биллинга нечитаем, пуст, несёт незнакомый заголовок или валюту ≠ USD —
 * {@see BillingReconciler}. Сообщение несёт конкретную причину и, где уместно,
 * найденные/ожидаемые колонки — команда печатает его как есть (§1 карты сервисного слоя).
 */
final class BillingExportUnreadable extends OperationFailed {}
