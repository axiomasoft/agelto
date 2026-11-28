<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Engine;

use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Parsing\Rules\CardRoutesRule;
use App\Services\Procurement\Platform\Contracts\CardUrlRouter;
use InvalidArgumentException;

/**
 * Универсальный маршрутизатор URL вкладок детальной карточки закупки.
 */
final class UniversalCardUrlRouter implements CardUrlRouter
{
    public function buildUrl(CardRoutesRule $routes, string $tab, string $baseUrl, string $regNumber): ?string
    {
        $template = match ($tab) {
            'common-info', 'common_info' => $routes->commonInfoPath,
            'documents' => $routes->documentsPath,
            'event-journal', 'event_journal', 'journal' => $routes->journalPath,
            'supplier-results', 'supplier_results' => $routes->supplierResultsPath,
            'lot' => $routes->lotPath,
            default => null,
        };

        if ($template === null || trim($template) === '') {
            return null;
        }

        $baseSafe = new SafeUrl($baseUrl);
        $resolved = trim(str_replace('{regNumber}', rawurlencode($regNumber), $template));

        if (str_starts_with($resolved, 'https://')) {
            $candidate = $resolved;
            $result = $resolved;
        } elseif (str_starts_with($resolved, '/') && ! str_starts_with($resolved, '//')) {
            $candidate = $baseSafe->origin->value().$resolved;
            $result = rtrim($baseUrl, '/').$resolved;
        } else {
            throw new InvalidArgumentException('Card route URL must be https:// or a relative path starting with "/".');
        }

        $candidateSafe = new SafeUrl($candidate);
        if (! $candidateSafe->origin->equals($baseSafe->origin)) {
            throw new InvalidArgumentException('Card route URL origin mismatch.');
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function availableTabs(CardRoutesRule $routes): array
    {
        $tabs = [];

        if ($routes->commonInfoPath !== null) {
            $tabs[] = 'common-info';
        }
        if ($routes->documentsPath !== null) {
            $tabs[] = 'documents';
        }
        if ($routes->journalPath !== null) {
            $tabs[] = 'event-journal';
        }
        if ($routes->supplierResultsPath !== null) {
            $tabs[] = 'supplier-results';
        }
        if ($routes->lotPath !== null) {
            $tabs[] = 'lot';
        }

        return $tabs;
    }
}
