<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Procurement\Network\UpdateRouteProfile;
use App\Enums\Procurement\Network\NetworkChangeStatus;
use App\Guards\Admin\Permissions\ProxyPermission;
use App\Models\Procurement\Network\NetworkRoute;
use App\Models\Procurement\Network\NetworkRouteProfile;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class NetworkRouteProfilesPage extends Page
{
    protected static ?string $slug = 'network-route-profiles';

    protected static string|\UnitEnum|null $navigationGroup = 'Сбор данных';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Профили маршрутов';

    protected string $view = 'filament.pages.network-route-profiles-page';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission(permission: ProxyPermission::Update, panelId: 'admin') ?? false;
    }

    public function mount(): void
    {
        $this->data = ['profile_key' => config()->string('outbound.routing.default_profile'), 'reason' => ''];
        $this->loadProfile();
    }

    public function getTitle(): string
    {
        return 'Профили маршрутов';
    }

    public function loadProfile(): void
    {
        abort_unless(self::canAccess(), 403);
        $key = $this->data['profile_key'] ?? '';
        if (! is_string($key)) {
            return;
        }
        $profile = NetworkRouteProfile::query()->where('profile_key', $key)->first();
        $this->data['policy_version'] = $profile->policy_version ?? 0;
        $this->data['route_ids'] = $profile === null ? [] : DB::table('prc_network_route_profile_members')->where('profile_id', $profile->id)->pluck('route_id')->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('profile_key')->label('Имя профиля')->required()->regex('/^[a-z][a-z0-9_.:-]{0,95}$/D')
                ->live(onBlur: true)->afterStateUpdated(fn () => $this->loadProfile()),
            Select::make('route_ids')->label('Разрешённые маршруты')->multiple()->searchable()
                ->options(fn (): array => NetworkRoute::query()->orderBy('route_key')->pluck('route_key', 'id')->all())
                ->helperText('Пустой профиль запрещает отправку. Прямой маршрут должен быть выбран явно.'),
            Textarea::make('reason')->label('Причина изменения')->required()->maxLength(200),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $schema = $this->getSchema('form') ?? throw new \LogicException('Route profile form is unavailable.');
        $state = $schema->getState();
        $key = $state['profile_key'] ?? null;
        $reason = $state['reason'] ?? null;
        $members = $state['route_ids'] ?? [];
        $version = $this->data['policy_version'] ?? null;
        if (! is_string($key) || ! is_string($reason) || ! is_int($version) || ! is_array($members)
            || ! array_is_list($members) || array_any($members, static fn (mixed $id): bool => ! is_string($id))) {
            throw new \InvalidArgumentException('Invalid route profile form.');
        }
        /** @var list<string> $members */
        $result = app(UpdateRouteProfile::class)->execute($actor, $key, $version, $members, $reason);
        if ($result->status === NetworkChangeStatus::VersionConflict) {
            Notification::make()->title('Профиль изменён другим оператором. Перезагрузите данные.')->danger()->send();

            return;
        }
        $this->loadProfile();
        Notification::make()->title('Профиль маршрутов сохранён')->success()->send();
    }
}
