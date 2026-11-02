<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

use App\Dto\Llm\Schema\CanonicalSchema;
use App\Enums\Llm\TaskPurpose;
use App\Services\Llm\Execution\ProviderGenerationInput;
use App\Services\Llm\Schema\CanonicalJson;

/** A closed, canonical list sealed before the first operation of an analysis run. */
final readonly class TaskManifest
{
    /** @var non-empty-list<TaskSnapshot> */
    public array $tasks;

    public string $json;

    /** @param array<array-key,TaskSnapshot> $tasks */
    public function __construct(array $tasks)
    {
        if (! array_is_list($tasks) || $tasks === [] || count($tasks) > count(TaskPurpose::cases())) {
            throw new \InvalidArgumentException('A task manifest requires a bounded nonempty list.');
        }
        $byPurpose = [];
        foreach ($tasks as $task) {
            if (isset($byPurpose[$task->purpose->value])) {
                throw new \InvalidArgumentException('Duplicate task purpose in pinned manifest.');
            }
            $byPurpose[$task->purpose->value] = $task;
        }
        ksort($byPurpose, SORT_STRING);
        $this->tasks = array_values($byPurpose);
        $this->json = CanonicalJson::encode(['version' => 1, 'tasks' => array_map(static fn (TaskSnapshot $t): array => $t->fields(), $this->tasks)]);
        if (strlen($this->json) > 2_097_152) {
            throw new \InvalidArgumentException('Pinned task manifest exceeds its bound.');
        }
    }

    public function task(TaskPurpose $purpose): TaskSnapshot
    {
        foreach ($this->tasks as $task) {
            if ($task->purpose === $purpose) {
                return $task;
            }
        }
        throw new \InvalidArgumentException('Task purpose is not pinned in this analysis recipe.');
    }

    public function matches(ProviderGenerationInput $input): bool
    {
        foreach ($this->tasks as $task) {
            if ($task->purpose === $input->purpose) {
                return $task->prompt == $input->system && $task->schema->hash === $input->schema->canonicalHash;
            }
        }

        return false;
    }

    public static function restore(string $json): self
    {
        if (strlen($json) > 2_097_152) {
            throw new \InvalidArgumentException('Pinned task manifest exceeds its bound.');
        }
        $value = json_decode($json, depth: 64, flags: JSON_THROW_ON_ERROR);
        if (! $value instanceof \stdClass || array_diff(array_keys(get_object_vars($value)), ['version', 'tasks']) !== []
            || ($value->version ?? null) !== 1 || ! is_array($value->tasks ?? null)) {
            throw new \InvalidArgumentException('Unknown task manifest codec.');
        }
        $tasks = [];
        foreach ($value->tasks as $entry) {
            if (! $entry instanceof \stdClass || ! is_string($entry->purpose ?? null) || ! is_int($entry->contract_version ?? null)
                || ! is_string($entry->prompt_content ?? null) || ! is_int($entry->prompt_version ?? null)
                || ! is_string($entry->prompt_hash ?? null) || ! is_string($entry->schema_hash ?? null) || ! ($entry->schema ?? null) instanceof \stdClass) {
                throw new \InvalidArgumentException('Invalid pinned task snapshot.');
            }
            $purpose = TaskPurpose::tryFrom($entry->purpose) ?? throw new \InvalidArgumentException('Unknown pinned task purpose.');
            $definition = [];
            foreach (get_object_vars($entry->schema) as $key => $schemaValue) {
                if (! is_string($key)) {
                    throw new \InvalidArgumentException('Invalid canonical schema root key.');
                }
                $definition[$key] = $schemaValue;
            }
            $task = new TaskSnapshot($purpose, new PromptSnapshot($entry->prompt_content, $entry->prompt_version),
                new CanonicalSchema($definition), $entry->contract_version);
            if ($task->prompt->hash !== $entry->prompt_hash || $task->schema->hash !== $entry->schema_hash) {
                throw new \InvalidArgumentException('Pinned task snapshot hash mismatch.');
            }
            $tasks[] = $task;
        }
        $manifest = new self($tasks);
        if ($manifest->json !== CanonicalJson::encode($value)) {
            throw new \InvalidArgumentException('Noncanonical or unrecognized task manifest fields.');
        }

        return $manifest;
    }
}
