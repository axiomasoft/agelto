<?php

declare(strict_types=1);

use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Crawler\Proxy;
use App\Services\Llm\Contracts\OutboundProxy;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Providers\PrismGeminiProvider;
use App\Services\Procurement\Proxy\PoolOutboundProxy;
use App\Services\Procurement\Proxy\ProxyResolver;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\Response as PrismStructuredResponse;
use Prism\Prism\ValueObjects\Meta as PrismMeta;
use Prism\Prism\ValueObjects\Usage as PrismUsage;
use Tests\Support\ProcessCore\Http\PermitFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PermitFixtures::clean();
    $this->network = new PermitFixtures;
});
afterEach(fn () => PermitFixtures::clean());

function llmAllowedMember(Proxy $proxy): void
{
    $profile = DB::table('prc_network_route_profiles')->where('profile_key', 'llm')->sole();
    DB::table('prc_network_route_profile_members')->insert([
        'profile_id' => $profile->id, 'route_id' => $proxy->route_id,
    ]);
}

test('резолвер не выдаёт запрещённый по стране узел для LLM-скоупа ни при одной конфигурации здоровья', function (): void {
    foreach (range(0, 2) as $index) {
        $proxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::Llm])->create();
        DB::table('prc_route_health')->where('route_id', $proxy->route_id)
            ->update(['consecutive_failures' => $index * 10, 'state' => $index === 2 ? 'open' : 'closed']);
    }
    // Country heuristics are retired; exclusion is the explicit profile allowlist.
    expect(app(ProxyResolver::class)->profile(provider: true)->bindings)->toBe([]);
});

test('узел без страны исключается из LLM-скоупа при непустой политике запретов', function (): void {
    Proxy::factory()->withScopes([ProxyScope::Llm])->create();
    expect(app(ProxyResolver::class)->profile(provider: true)->bindings)->toBe([]);
});

test('разрешённый по стране узел для LLM-скоупа выдаётся резолвером', function (): void {
    Proxy::factory()->country('RU')->withScopes([ProxyScope::Llm])->create();
    $allowed = Proxy::factory()->country('DE')->withScopes([ProxyScope::Llm])->create();
    llmAllowedMember($allowed);
    $bindings = app(ProxyResolver::class)->profile(provider: true)->bindings;
    expect($bindings)->toHaveCount(1)->and(array_values($bindings)[0]->id->value)->toBe($allowed->route_id);
});

test('PoolOutboundProxy отдаёт httpOptions разрешённого узла для LLM-скоупа', function (): void {
    $allowed = Proxy::factory()->country('DE')->withScopes([ProxyScope::Llm])->create();
    llmAllowedMember($allowed);
    $options = app(PoolOutboundProxy::class)->optionsFor('generativelanguage.googleapis.com');
    expect(array_keys($options))->toBe(['allow_redirects', 'http_errors', 'handler'])
        ->and($options['allow_redirects'])->toBeFalse()->and($options['http_errors'])->toBeFalse()
        ->and($options['handler'])->toBeInstanceOf(Closure::class)
        ->and($options)->not->toHaveKey('proxy');
});

test('пустой пул для LLM остаётся fail-closed даже при legacy env-прокси', function (): void {
    config(['ai.gemini.proxy' => 'http://legacy-env-proxy.test:8080']);
    $options = app(PoolOutboundProxy::class)->optionsFor('generativelanguage.googleapis.com', 'gemini');
    expect(fn () => $options['handler'](new Request('GET', 'https://generativelanguage.googleapis.com/models'), []))
        ->toThrow(OutboundUnavailable::class)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('пустая политика запретов для LLM-скоупа — резолвер бросает ProxyPolicyMisconfiguredException', function (): void {
    config(['outbound.routing.provider_profile' => '']);
    expect(fn () => app(ProxyResolver::class)->profile(provider: true))->toThrow(InvalidArgumentException::class, 'provider_profile');
});

test('предохранитель пустой политики LLM не подменяется legacy env-прокси', function (): void {
    config(['outbound.routing.provider_profile' => '', 'ai.gemini.proxy' => 'http://env-proxy.test:8080']);
    expect(fn () => app(PoolOutboundProxy::class))->toThrow(InvalidArgumentException::class, 'provider_profile')
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('PrismGeminiProvider запрашивает опции у порта OutboundProxy по каноническому хосту Gemini', function (): void {
    $outboundProxy = new class implements OutboundProxy
    {
        /** @var list<string> */
        public array $requestedHosts = [];

        public function optionsFor(string $host, ?string $providerKey = null): array
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
