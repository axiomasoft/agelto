<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Procurement\Proxy\UpdateCrawlerProxySettingsAction;
use App\Dto\Actions\Procurement\Proxy\UpdateCrawlerProxySettingsCommand;
use App\Guards\Admin\Permissions\ProxyPermission;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;

final class CrawlerProxySettingsPage extends Page
{
    protected static ?string $slug = 'crawler-proxy-settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Сбор данных';

    protected static ?int $navigationSort = 40;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Прокси по умолчанию';

    protected string $view = 'filament.pages.crawler-proxy-settings-page';

    /** @var array{default_proxy_id?: int|null} */
    public array $data = [];

    public function mount(): void
    {
        $this->formSchema()->fill([
            'default_proxy_id' => app(UpdateCrawlerProxySettingsAction::class)->currentDefaultProxyId(),
        ]);
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission(permission: ProxyPermission::Update, panelId: 'admin') ?? false;
    }

    public function getTitle(): string
    {
        return 'Прокси по умолчанию';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('default_proxy_id')
                    ->label('Прокси по умолчанию')
                    ->options(fn (): array => app(UpdateCrawlerProxySettingsAction::class)->defaultProxyOptions())
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Не выбран — профили в режиме «По умолчанию» работают напрямую. Недоступный настроенный прокси останавливает запрос, а не меняет маршрут.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        try {
            $data = $this->formSchema()->getState();
            $defaultProxyId = $data['default_proxy_id'] ?? null;

            app(UpdateCrawlerProxySettingsAction::class)->run(
                command: new UpdateCrawlerProxySettingsCommand(
                    defaultProxyId: is_numeric($defaultProxyId) ? (int) $defaultProxyId : null,
                ),
            );
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Настройки прокси сохранены')->success()->send();
    }

    private function formSchema(): Schema
    {
        return $this->getSchema('form')
            ?? throw new LogicException('Не удалось создать форму настройки прокси.');
    }
}
