<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use Illuminate\Contracts\Config\Repository;

/** Versioned startup engineering defaults; runtime evidence never rewrites configuration. */
final readonly class AdapterHealthPolicy
{
    public int $version;

    public int $distinctInputs;

    public int $windowSeconds;

    public int $rampSuccesses;

    public int $normalConcurrency;

    public int $inputLayoutAttempts;

    public int $inputFaultAttempts;

    public function __construct(Repository $config)
    {
        $keys = ['version' => 'policy_version', 'distinctInputs' => 'distinct_inputs', 'windowSeconds' => 'window_seconds',
            'rampSuccesses' => 'ramp_successes', 'normalConcurrency' => 'normal_concurrency',
            'inputLayoutAttempts' => 'input_layout_attempts', 'inputFaultAttempts' => 'input_fault_attempts'];
        $data = $config->get('outbound.adapter_health');
        if (! is_array($data) || count($data) !== count($keys) || array_diff(array_keys($data), array_values($keys)) !== []) {
            throw new \InvalidArgumentException('Invalid outbound.adapter_health.');
        }
        $integer = static function (mixed $value, string $key): int {
            if (! is_int($value) || $value < 1 || $value > 3600) {
                throw new \InvalidArgumentException('Invalid outbound.adapter_health.'.$key);
            }

            return $value;
        };
        $this->version = $integer($data['policy_version'], 'policy_version');
        $this->distinctInputs = $integer($data['distinct_inputs'], 'distinct_inputs');
        $this->windowSeconds = $integer($data['window_seconds'], 'window_seconds');
        $this->rampSuccesses = $integer($data['ramp_successes'], 'ramp_successes');
        $this->normalConcurrency = $integer($data['normal_concurrency'], 'normal_concurrency');
        $this->inputLayoutAttempts = $integer($data['input_layout_attempts'], 'input_layout_attempts');
        $this->inputFaultAttempts = $integer($data['input_fault_attempts'], 'input_fault_attempts');
        if ($this->distinctInputs < 3 || $this->distinctInputs > 16 || $this->normalConcurrency < 2
            || $this->normalConcurrency > 64 || $this->rampSuccesses < 3 || $this->rampSuccesses > 64) {
            throw new \InvalidArgumentException('Inconsistent outbound.adapter_health thresholds.');
        }
    }

    public function capacity(int $acceptedInputs): int
    {
        return $acceptedInputs === 0 ? 1 : ($acceptedInputs < $this->rampSuccesses ? 2 : $this->normalConcurrency);
    }
}
