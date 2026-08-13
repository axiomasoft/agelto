<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Proxy;

use App\Dto\Actions\Procurement\Proxy\UpdateCrawlerProxySettingsCommand;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Guards\Admin\Permissions\ProxyPermission;
use App\Models\Procurement\Crawler\Proxy;
use App\Settings\Procurement\CrawlerProxySettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final readonly class UpdateCrawlerProxySettingsAction
{
    private const array REQUIRED_SCOPES = [
        ProxyScope::CrawlListing,
        ProxyScope::CrawlDetail,
        ProxyScope::DocumentDownload,
    ];

    public function __construct(
        private CrawlerProxySettings $settings,
    ) {}

    public function currentDefaultProxyId(): ?int
    {
        $this->settings->refresh();

        return $this->settings->defaultProxyId;
    }

    /** @return array<int, string> */
    public function defaultProxyOptions(): array
    {
        return Proxy::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Proxy $proxy): bool => $this->supportsCrawlerScopes(proxy: $proxy))
            ->mapWithKeys(fn (Proxy $proxy): array => [$proxy->getKey() => $this->safeProxyLabel(proxy: $proxy)])
            ->all();
    }

    public function run(UpdateCrawlerProxySettingsCommand $command): void
    {
        $this->authorize();

        $this->validateDefaultProxyId(defaultProxyId: $command->defaultProxyId);

        $this->settings->defaultProxyId = $command->defaultProxyId;
        $this->settings->save();
    }

    private function authorize(): void
    {
        if (Auth::user()?->hasPermission(permission: ProxyPermission::Update, panelId: 'admin') ?? false) {
            return;
        }

        throw new AuthorizationException('Недостаточно прав для изменения настроек прокси.');
    }

    private function validateDefaultProxyId(?int $defaultProxyId): void
    {
        if ($defaultProxyId === null) {
            return;
        }

        $proxy = Proxy::query()->find($defaultProxyId);

        if (! $proxy instanceof Proxy || ! $proxy->is_active || ! $this->supportsCrawlerScopes(proxy: $proxy)) {
            throw ValidationException::withMessages([
                'default_proxy_id' => 'Выберите активный прокси с областями listing, detail и document.',
            ]);
        }
    }

    private function supportsCrawlerScopes(Proxy $proxy): bool
    {
        $scopes = $proxy->scopes;

        return collect(self::REQUIRED_SCOPES)
            ->every(fn (ProxyScope $scope): bool => $scopes->contains(
                fn (ProxyScope $proxyScope): bool => $proxyScope === $scope,
            ));
    }

    private function safeProxyLabel(Proxy $proxy): string
    {
        $address = $proxy->port === null
            ? $proxy->host
            : "{$proxy->host}:{$proxy->port}";
        $country = $proxy->country ?? 'страна не указана';

        return "{$proxy->service} — {$address} ({$country})";
    }
}
