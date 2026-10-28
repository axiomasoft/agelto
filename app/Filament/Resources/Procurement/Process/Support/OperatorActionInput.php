<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\Support;

use App\Dto\Procurement\Process\OperatorCommandResult;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Версия, которую оператор подтвердил при открытии модального окна, и разбор ввода операторских actions.
 *
 * Состояние Livewire-формы приходит от клиента, поэтому версия хранится в подписанном токене: подпись связывает
 * её с оператором, action и записью, так что подмена или перенос на другую запись отклоняются.
 */
final class OperatorActionInput
{
    public const string FIELD = 'expected_version';

    public const int REASON_LIMIT = 200;

    public static function versionField(string $actionName): Hidden
    {
        return Hidden::make(self::FIELD)->required()->default(
            static function (Model $record) use ($actionName): string {
                $key = $record->getKey();
                $version = $record->getAttribute('transition_seq');

                return self::issue(
                    $actionName,
                    is_scalar($key) ? (string) $key : '',
                    is_numeric($version) ? (int) $version : 0,
                );
            },
        );
    }

    public static function issue(string $actionName, string $recordId, int $version, ?string $userId = null): string
    {
        return $version.'.'.self::signature($actionName, $recordId, $version, $userId ?? self::userId());
    }

    /**
     * Подтверждённая версия или null, если токен подделан, выдан для другой записи/action/оператора или повреждён.
     */
    public static function version(string $actionName, string $recordId, mixed $token, ?string $userId = null): ?int
    {
        if (! is_string($token) || preg_match('/^(0|[1-9][0-9]{0,17})\.([0-9a-f]{64})$/', $token, $parts) !== 1) {
            return null;
        }
        $version = (int) $parts[1];

        return hash_equals(self::signature($actionName, $recordId, $version, $userId ?? self::userId()), $parts[2]) ? $version : null;
    }

    /**
     * Версия из данных формы; недействительный токен закрывает окно с предупреждением и не вызывает команду.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function confirmedVersion(Action $action, string $actionName, string $recordId, array $data): int
    {
        $version = self::version($actionName, $recordId, $data[self::FIELD] ?? null);
        if ($version === null) {
            Notification::make()->title('Подтверждение недействительно: откройте окно действия заново')->danger()->send();
            $action->cancel();
        }

        return (int) $version;
    }

    /** @param array<array-key, mixed> $data */
    public static function reason(array $data): string
    {
        $reason = $data['reason'] ?? '';

        return is_string($reason) ? $reason : '';
    }

    /**
     * Недопустимые аргументы команды (пустая или слишком длинная причина) возвращаются в форму, а не уходят исключением.
     *
     * @param  \Closure(): OperatorCommandResult  $command
     */
    public static function attempt(Action $action, \Closure $command): OperatorCommandResult
    {
        try {
            return $command();
        } catch (\InvalidArgumentException) {
            Notification::make()->title(sprintf('Причина обязательна и не длиннее %d символов', self::REASON_LIMIT))->danger()->send();
            $action->halt();

            throw new \LogicException('Action::halt() must interrupt the command.');
        }
    }

    private static function signature(string $actionName, string $recordId, int $version, string $userId): string
    {
        $key = config('app.key');
        if (! is_string($key)) {
            throw new \LogicException('Process control requires app.key to sign operator action tokens.');
        }

        return hash_hmac('sha256', implode('|', ['process-operator-action', $actionName, $recordId, $version, $userId]), $key);
    }

    private static function userId(): string
    {
        $id = Auth::id();

        return is_int($id) || is_string($id) ? (string) $id : throw new \LogicException('Process control requires an authenticated admin user.');
    }
}
