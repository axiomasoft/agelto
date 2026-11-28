<?php

declare(strict_types=1);

use Illuminate\Database\PostgresConnection;
use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Policy\CoreSettings;
use Proxyma\Application\Policy\SettingRegistry;
use Proxyma\Application\Policy\UpdatePolicy;
use Proxyma\Contracts\Authorizer;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\PolicyConflict;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\TenantContext;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Policy\UpdatePolicyCommand;

require dirname(__DIR__, 4).'/vendor/autoload.php';

[$script, $schema, $headId, $worker] = $argv;
if (preg_match('/^pxm_t_[a-f0-9]{16}$/D', $schema) !== 1 || ! in_array($worker, ['one', 'two'], true)) {
    throw new RuntimeException('Invalid isolated worker fixture.');
}
if (getenv('PROCESS_CORE_TEST') !== '1' || ! str_ends_with((string) getenv('PROXYMA_REPLAY_DSN'), ';dbname=agelto_test')) {
    throw new RuntimeException('Worker requires canonical isolated replay DSN.');
}
$pdo = new PDO(getenv('PROXYMA_REPLAY_DSN'), getenv('PROXYMA_REPLAY_USER'), getenv('PROXYMA_REPLAY_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'agelto_test') {
    throw new RuntimeException('Worker database identity mismatch.');
}
$pdo->exec('SET search_path TO '.$schema);
$name = 'proxyma-p21-'.$schema.'-'.$worker;
$pdo->prepare("SELECT set_config('application_name', ?, false)")->execute([$name]);
$db = new PostgresConnection($pdo, 'agelto_test');
$tenant = new TenantKey('acme');
$actor = new ActorRef('operator', 'fixture-operator');
$resolver = new class($tenant, $actor) implements TenantContextResolver
{
    public function __construct(private TenantKey $tenant, private ActorRef $actor) {}

    public function resolve(): TenantContext
    {
        return new TenantContext($this->tenant, $this->actor);
    }
};
$authorizer = new class implements Authorizer
{
    public function authorize(ActorRef $actor, TenantKey $tenant, string $ability, Uuid $resourceId): void {}
};
$service = new UpdatePolicy($db, new CatalogAccess($resolver, $authorizer), new SettingRegistry(CoreSettings::descriptors()));
$command = new UpdatePolicyCommand(new Uuid($headId), $tenant, PolicyScope::Tenant, 'acme',
    json_encode(['schema_version' => 1, 'operations' => ['retry.max_attempts' => ['op' => 'set', 'value' => $worker === 'one' ? 2 : 3]]], JSON_THROW_ON_ERROR),
    new CatalogMutationActor($actor, 'concurrent update', 1, 'parallel-'.$worker));
try {
    $result = $service->update($command);
    echo json_encode(['status' => 'updated', 'revision' => $result->revision], JSON_THROW_ON_ERROR);
} catch (PolicyConflict) {
    echo json_encode(['status' => 'conflict'], JSON_THROW_ON_ERROR);
}
