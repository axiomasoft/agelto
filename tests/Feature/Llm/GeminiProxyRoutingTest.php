<?php

declare(strict_types=1);

use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use App\Exceptions\Procurement\Proxy\ProxyPolicyMisconfiguredException;
use App\Models\Procurement\Crawler\Proxy;
use App\Repositories\Procurement\Proxy\ProxyHealthStoreRepository;
use App\Services\Llm\Contracts\OutboundProxy;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Providers\PrismGeminiProvider;
use App\Services\Procurement\Proxy\PoolOutboundProxy;
use App\Services\Procurement\Proxy\ProxyCircuitBreaker;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\ProxyScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\Response as PrismStructuredResponse;
use Prism\Prism\ValueObjects\Meta as PrismMeta;
use Prism\Prism\ValueObjects\Usage as PrismUsage;

uses(RefreshDatabase::class);

function llmResolver(?Closure $randomSource = null): ProxyResolver
{
    return new ProxyResolver(
        scoreCalculator: new ProxyScoreCalculator,
        breaker: new ProxyCircuitBreaker,
        health: new ProxyHealthStoreRepository,
        randomSource: $randomSource,
    );
}

test('резолвер не выдаёт запрещённый по стране узел для LLM-скоупа ни при одной конфигурации здоровья', function (): void {
    Proxy::factory()->country('RU')->withScopes([ProxyScope::Llm])->create(['fails_count' => 0]);
    Proxy::factory()->country('RU')->withScopes([ProxyScope::Llm])->create(['fails_count' => 20]);
    Proxy::factory()->country('RU')->withScopes([ProxyScope::Llm])->open()->create();

    foreach ([0.0, 0.5, 0.999999] as $randomValue) {
        expect(fn () => llmResolver(fn (): float => $randomValue)
            ->resolve(scope: ProxyScope::Llm, target: 'generativelanguage.googleapis.com'))
            ->toThrow(NoProxyAvailableException::class);
    }
});

test('узел без страны исключается из LLM-скоупа при непустой политике запретов', function (): void {
    Proxy::factory()->withScopes([ProxyScope::Llm])->create();

    expect(fn () => llmResolver()->resolve(scope: ProxyScope::Llm, target: 'generativelanguage.googleapis.com'))
        ->toThrow(NoProxyAvailableException::class);
});

test('разрешённый по стране узел для LLM-скоупа выдаётся резолвером', function (): void {
    Proxy::factory()->country('RU')->withScopes([ProxyScope::Llm])->create();
    $allowed = Proxy::factory()->country('DE')->withScopes([ProxyScope::Llm])->create();

    $lease = llmResolver()->resolve(scope: ProxyScope::Llm, target: 'generativelanguage.googleapis.com');

    expect($lease->proxyId)->toBe($allowed->id);
});

test('PoolOutboundProxy отдаёт httpOptions разрешённого узла для LLM-скоупа', function (): void {
    $allowed = Proxy::factory()->country('DE')->withScopes([ProxyScope::Llm])->create();

    $adapter = new PoolOutboundProxy(llmResolver());

    expect($adapter->optionsFor('generativelanguage.googleapis.com'))
        ->toBe(['proxy' => "http://{$allowed->username}:{$allowed->password}@{$allowed->host}:{$allowed->port}"]);
});

test('пустой пул для LLM и пустой env — PoolOutboundProxy бросает NoProxyAvailableException', function (): void {
    config(['ai.gemini.proxy' => null]);

    $adapter = new PoolOutboundProxy(llmResolver());

    expect(fn () => $adapter->optionsFor('generativelanguage.googleapis.com'))
        ->toThrow(NoProxyAvailableException::class);
});

test('пустой пул для LLM фолбэчит на env-прокси с явным предупреждением в логе', function (): void {
    config(['ai.gemini.proxy' => 'http://env-proxy.test:8080']);
    Log::spy();

    $adapter = new PoolOutboundProxy(llmResolver());

    expect($adapter->optionsFor('generativelanguage.googleapis.com'))
        ->toBe(['proxy' => 'http://env-proxy.test:8080']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'env-фолбэк')
            && $context['host'] === 'generativelanguage.googleapis.com');
});

test('пустая политика запретов для LLM-скоупа — резолвер бросает ProxyPolicyMisconfiguredException', function (): void {
    config(['crawler.proxy.policy.'.ProxyScope::Llm->value.'.forbidden_countries' => []]);

    expect(fn () => llmResolver()->resolve(scope: ProxyScope::Llm, target: 'generativelanguage.googleapis.com'))
        ->toThrow(ProxyPolicyMisconfiguredException::class);
});

test('предохранитель пустой политики LLM не ловится PoolOutboundProxy — env-фолбэк не срабатывает', function (): void {
    config([
        'crawler.proxy.policy.'.ProxyScope::Llm->value.'.forbidden_countries' => [],
        'ai.gemini.proxy' => 'http://env-proxy.test:8080',
    ]);

    $adapter = new PoolOutboundProxy(llmResolver());

    expect(fn () => $adapter->optionsFor('generativelanguage.googleapis.com'))
        ->toThrow(ProxyPolicyMisconfiguredException::class);
});

test('PrismGeminiProvider запрашивает опции у порта OutboundProxy по каноническому хосту Gemini', function (): void {
    $outboundProxy = new class implements OutboundProxy
    {
        /** @var list<string> */
        public array $requestedHosts = [];

        public function optionsFor(string $host): array
        {
            $this->requestedHosts[] = $host;

            return ['proxy' => 'http://from-port.test:1'];
        }
    };

    Prism::fake([
        new PrismStructuredResponse(
            steps: collect([]),
            text: '{}',
            structured: [],
            finishReason: PrismFinishReason::Stop,
            usage: new PrismUsage(promptTokens: 0, completionTokens: 0),
            meta: new PrismMeta('id-1', 'gemini-2.5-flash'),
            raw: ['usageMetadata' => []],
        ),
    ]);

    $provider = new PrismGeminiProvider($outboundProxy);
    $provider->completeStructured(
        new LlmPrompt(systemPart: 's'),
        new JsonSchema(['type' => 'object']),
        new LlmOptions(provider: 'gemini', model: 'gemini-2.5-flash', temperature: 0.0, maxTokens: 100),
    );

    expect($outboundProxy->requestedHosts)->toBe(['generativelanguage.googleapis.com']);
});
