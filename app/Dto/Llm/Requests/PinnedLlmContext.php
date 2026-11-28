<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

use App\Services\Llm\Schema\CanonicalJson;

/** Contains identifiers, never credentials. No route/config lookup on resume. */
final readonly class PinnedLlmContext
{
    public string $json;

    /**
     * @param  array<string, bool|int|string|null>  $options
     * @param  array<string, bool|int|string|null>  $thinking
     */
    public function __construct(
        public string $provider,
        public string $account,
        public string $apiVersion,
        public string $model,
        public string $routePolicyVersion,
        public array $options,
        public array $thinking,
    ) {
        foreach ([$provider, $account, $apiVersion, $model, $routePolicyVersion] as $identity) {
            if ($identity === '' || strlen($identity) > 256) {
                throw new \InvalidArgumentException('Missing pinned provider identity.');
            }
        }
        self::options((object) $options);
        self::options((object) $thinking);
        $this->json = CanonicalJson::encode(['provider' => $provider, 'account' => $account, 'api_version' => $apiVersion,
            'model' => $model, 'route_policy_version' => $routePolicyVersion, 'options' => (object) $options, 'thinking' => (object) $thinking]);
    }

    public static function restore(string $json): self
    {
        $value = json_decode($json, flags: JSON_THROW_ON_ERROR);
        if (! $value instanceof \stdClass) {
            throw new \InvalidArgumentException('Invalid pinned context.');
        }
        $fields = get_object_vars($value);
        $strings = [];
        foreach (['provider', 'account', 'api_version', 'model', 'route_policy_version'] as $key) {
            if (! is_string($fields[$key] ?? null)) {
                throw new \InvalidArgumentException('Invalid pinned context field: '.$key);
            }
            $strings[$key] = $fields[$key];
        }
        $context = new self($strings['provider'], $strings['account'], $strings['api_version'], $strings['model'], $strings['route_policy_version'],
            self::options($fields['options'] ?? null), self::options($fields['thinking'] ?? null));
        if ($context->json !== CanonicalJson::encode($value)) {
            throw new \InvalidArgumentException('Unrecognized pinned context fields.');
        }

        return $context;
    }

    /** @return array<string, bool|int|string|null> */
    private static function options(mixed $value): array
    {
        if (! $value instanceof \stdClass) {
            throw new \InvalidArgumentException('Options must be a closed snapshot object.');
        }
        $options = [];
        foreach (get_object_vars($value) as $key => $option) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('Option names must be strings.');
            }
            if (! is_bool($option) && ! is_int($option) && ! is_string($option) && $option !== null) {
                throw new \InvalidArgumentException('Options require exact scalar values.');
            }
            $options[$key] = $option;
        }

        return $options;
    }
}
