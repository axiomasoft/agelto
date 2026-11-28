<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform\Concerns;

use App\Enums\Procurement\Process\Core\StageCode;
use App\Models\Procurement\Parsing\ParsingRuleSet;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Parsing\ParsingRuleReadRepository;
use JsonException;
use ValueError;

/**
 * Общие вспомогательные методы для Artisan-команд управления правилами парсинга.
 */
trait InteractsWithParsingRules
{
    /**
     * @return array{platform: Platform, stage: StageCode, registry: ?string, scope: string}|null
     */
    private function resolveTargetCoordinates(): ?array
    {
        $platformCode = $this->argument('platform');
        $stageRaw = $this->argument('stage');
        $registryRaw = $this->option('registry');
        $scopeRaw = $this->option('scope');

        if (trim($platformCode) === '') {
            $this->error('Аргумент platform обязателен.');

            return null;
        }

        if (trim($stageRaw) === '') {
            $this->error('Аргумент stage обязателен.');

            return null;
        }

        try {
            $stage = StageCode::from(trim($stageRaw));
        } catch (ValueError) {
            $this->error(sprintf('Неизвестная стадия: %s.', $stageRaw));

            return null;
        }

        $platform = Platform::query()->where('code', trim($platformCode))->first();
        if ($platform === null) {
            $this->error(sprintf('Площадка с кодом "%s" не найдена.', $platformCode));

            return null;
        }

        $registry = is_string($registryRaw) && trim($registryRaw) !== '' ? trim($registryRaw) : null;
        $scope = is_string($scopeRaw) && trim($scopeRaw) !== '' ? trim($scopeRaw) : 'default';

        return [
            'platform' => $platform,
            'stage' => $stage,
            'registry' => $registry,
            'scope' => $scope,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonRulesFile(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error(sprintf('Файл правил "%s" не найден или недоступен для чтения.', $path));

            return null;
        }

        $content = file_get_contents($path);
        if (! is_string($content)) {
            $this->error(sprintf('Не удалось прочитать файл "%s".', $path));

            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error(sprintf('Ошибка JSON в файле "%s": %s', $path, $e->getMessage()));

            return null;
        }

        if (! is_array($decoded)) {
            $this->error(sprintf('Файл "%s" должен содержать JSON-объект правил.', $path));

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<array{name: string, part: string, body: string, negative?: bool}>|null
     */
    private function loadSampleFiles(mixed $rawSamples, ?string $part): ?array
    {
        $paths = is_array($rawSamples)
            ? array_values(array_filter($rawSamples, static fn (mixed $v): bool => is_string($v) && trim($v) !== ''))
            : (is_string($rawSamples) && trim($rawSamples) !== '' ? [trim($rawSamples)] : []);

        $effectivePart = $part !== null && trim($part) !== '' ? trim($part) : 'default';
        $samples = [];

        foreach ($paths as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                $this->error(sprintf('Файл образца "%s" не найден или недоступен.', $path));

                return null;
            }

            $body = file_get_contents($path);
            if (! is_string($body)) {
                $this->error(sprintf('Не удалось прочитать файл образца "%s".', $path));

                return null;
            }

            $samples[] = [
                'name' => basename($path),
                'part' => $effectivePart,
                'body' => $body,
                'negative' => false,
            ];
        }

        return $samples;
    }

    private function findRuleSetOrError(
        ParsingRuleReadRepository $readRepository,
        Platform $platform,
        StageCode $stage,
        ?string $registry,
        string $scope,
    ): ?ParsingRuleSet {
        $ruleSet = $readRepository->findRuleSet($platform->id, $stage, $registry, $scope);
        if ($ruleSet === null) {
            $this->error(sprintf(
                'Набор правил не найден (platform=%s, stage=%s, registry=%s, scope=%s).',
                $platform->code,
                $stage->value,
                $registry ?? 'null',
                $scope,
            ));

            return null;
        }

        return $ruleSet;
    }
}
