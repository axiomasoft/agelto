<?php

declare(strict_types=1);

namespace App\Listeners\Procurement;

use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Events\Procurement\PlatformHealthChanged;
use App\Notifications\PipelineAlert;

/** Синхронный слушатель: транспорт уже владеет HTTP-таймаутом и повторами. */
final readonly class NotifyPlatformHealthChanged
{
    public function __construct(private PipelineAlert $alerts) {}

    public function handle(PlatformHealthChanged $event): void
    {
        $this->alerts->notify([
            sprintf(
                'platform_health:%d:%s:%s',
                $event->platformId,
                $event->checkKind->value,
                $event->verdict->value,
            ) => $this->messageFor(event: $event),
        ]);
    }

    private function messageFor(PlatformHealthChanged $event): string
    {
        $check = $this->checkName(kind: $event->checkKind);

        if ($event->verdict === PlatformCheckVerdict::Ok) {
            return "Площадка #{$event->platformId}: {$check}; вердикт вернулся к ok. {$event->message}";
        }

        if ($event->previousVerdict === $event->verdict) {
            return "Площадка #{$event->platformId}: {$check}; остаётся {$event->verdict->value}. {$event->message}";
        }

        return "Площадка #{$event->platformId}: {$check}; стало {$event->verdict->value}. {$event->message}";
    }

    private function checkName(PlatformCheckKind $kind): string
    {
        return match ($kind) {
            PlatformCheckKind::CardParse => 'разбор карточек (якоря и ключевые поля)',
            PlatformCheckKind::ListingShape => 'форма листинга (строки и HTTP-статусы)',
            PlatformCheckKind::EstimateFields => 'поля смет (валидация и позиции)',
            PlatformCheckKind::ListingFilter => 'действие ОКПД2-фильтра',
        };
    }
}
