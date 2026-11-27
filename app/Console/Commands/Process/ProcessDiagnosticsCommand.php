<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Notifications\PipelineAlert;
use App\Services\Procurement\Process\Diagnostics\ProcessDiagnosticsInspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Read-only диагностика документов, AI-анализов и смет: проверки собирают признаки зависших
 * стадий, массовых ошибок и backlog, не меняя состояние ledger.
 *
 * Находки агрегируются в ОДНО Telegram-сообщение через `PipelineAlert::notify()`
 * (Code Guidance) с антиспамом по ключу находки (не чаще раза в час на одинаковую находку).
 */
#[Description('Проверяет зависшие/аномальные состояния пайплайна и алертит агрегированным сообщением')]
#[AsCommand(name: 'process:diagnostics')]
#[Signature('process:diagnostics')]
final class ProcessDiagnosticsCommand extends Command
{
    public function __construct(
        private readonly PipelineAlert $alert,
        private readonly ?ProcessDiagnosticsInspector $inspector = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $findings = ($this->inspector ?? app(ProcessDiagnosticsInspector::class))->inspectAll();

        if ($findings === []) {
            $this->info('Зависших/аномальных состояний не обнаружено.');

            return self::SUCCESS;
        }

        foreach ($findings as $message) {
            $this->warn($message);
        }

        $this->alert->notify($findings);

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    public function failureRateSpike(): array
    {
        return ($this->inspector ?? app(ProcessDiagnosticsInspector::class))->failureRateSpike();
    }
}
