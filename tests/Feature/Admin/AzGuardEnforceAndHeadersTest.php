<?php

declare(strict_types=1);

use App\Filament\Pages\FinalPromptPage;
use App\Filament\Pages\PromptCompositionPage;
use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use App\Filament\Resources\Procurement\Ai\Providers\Pages\CreateProvider;
use App\Filament\Resources\Procurement\Ai\Providers\ProviderResource;
use App\Filament\Resources\Procurement\Procurements\Pages\ViewProcurement;
use App\Filament\Resources\Procurement\Procurements\RelationManagers\EventsRelationManager;
use App\Guards\Admin\Permissions\ProcessControlPermission;
use App\Guards\Admin\Permissions\PromptPermission;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Procurement;
use App\Models\User;
use App\Services\Documentation\PrivateDocumentationLocator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('панель отправляет заголовки безопасности и включает HSTS через доверенный прокси', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/admin/login')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()')
        ->assertHeader('Content-Security-Policy', "base-uri 'self'; frame-ancestors 'none'; object-src 'none'")
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

test('панель игнорирует признак HTTPS от недоверенного адреса', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->get('/admin/login')
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('право чтения промтов не открывает страницу композиции без отдельного гранта', function (): void {
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    $analyst->grantPermission(PromptPermission::ViewAny);
    $this->actingAs($analyst);

    expect(PromptResource::canViewAny())->toBeTrue();
    $this->get(PromptCompositionPage::getUrl())
        ->assertForbidden()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $analyst->grantPermission(ProcessControlPermission::PromptCompositionPageView);
    $this->get(PromptCompositionPage::getUrl())->assertOk();
});

test('аналитик открывает карточку финального промта с грантом страницы', function (): void {
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    $this->actingAs($analyst);

    $this->get(FinalPromptPage::getUrl(['run' => (string) Str::uuid()]))->assertOk();
});

test('отдельное удаление не выдаёт права на массовое удаление', function (): void {
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $admin = User::factory()->create();
    $admin->assignRole(SuperAdminRole::class);
    $this->actingAs($admin);

    expect(ProviderResource::canDelete(Provider::factory()->create()))->toBeTrue()
        ->and(ProviderResource::canDeleteAny())->toBeFalse()
        ->and(PromptResource::canDeleteAny())->toBeFalse();
});

test('карточка обучения скрыта и при переходе по прямому URL', function (): void {
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    $this->actingAs($analyst);

    $trainingPlatform = Platform::query()->firstOrCreate(
        attributes: ['code' => Platform::TRAINING_CODE],
        values: Platform::factory()->training()->raw(),
    );
    $trainingDocument = Document::factory()->create(['platform_id' => $trainingPlatform->id]);
    $trainingVersion = DocumentVersion::factory()->create(['document_id' => $trainingDocument->id]);
    $trainingAnalysis = Analysis::factory()->create(['document_version_id' => $trainingVersion->id]);
    $publicAnalysis = Analysis::factory()->create();

    $this->get(AnalysisResource::getUrl(name: 'view', parameters: ['record' => $publicAnalysis]))->assertOk();
    $this->get(AnalysisResource::getUrl(name: 'view', parameters: ['record' => $trainingAnalysis]))->assertNotFound();
});

test('история закупки закрыта при прямом обращении к компоненту без гранта', function (): void {
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    $this->actingAs($operator);
    $procurement = Procurement::factory()->create();

    expect(EventsRelationManager::canViewForRecord(ownerRecord: $procurement, pageClass: ViewProcurement::class))
        ->toBeFalse();
    Livewire::test(EventsRelationManager::class, [
        'ownerRecord' => $procurement,
        'pageClass' => ViewProcurement::class,
    ])->assertForbidden();
});

test('форма подключения провайдера отклоняет невалидный URL', function (): void {
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    $this->actingAs($operator);

    Livewire::test(CreateProvider::class)
        ->fillForm([
            'code' => 'provider-test',
            'driver' => 'openai',
            'base_url' => 'не URL',
        ])
        ->call('create')
        ->assertHasFormErrors(['base_url' => 'url']);
});

test('главная страница документации отсутствует после удаления индекса из манифеста', function (): void {
    $root = sys_get_temp_dir().'/agelto-docs-'.Str::uuid();
    File::ensureDirectoryExists($root.'/site');
    config(['documentation.root' => $root]);

    try {
        $manifestPath = $root.'/manifest.json';
        $index = ['path' => 'index.html', 'mime' => 'text/html; charset=utf-8', 'sha256' => hash('sha256', 'index')];
        $article = ['path' => 'article.html', 'mime' => 'text/html; charset=utf-8', 'sha256' => hash('sha256', 'article')];
        File::put($root.'/site/index.html', 'index');
        File::put($manifestPath, json_encode([$article, $index], flags: JSON_THROW_ON_ERROR));

        $locator = new PrivateDocumentationLocator;
        expect($locator->locate(requestedPath: null)->path)->toBe('index.html');
        $mtime = File::lastModified($manifestPath);

        $renamedIndex = [...$index, 'path' => 'other.html'];
        File::put($root.'/site/other.html', 'index');
        File::put($manifestPath, json_encode([$article, $renamedIndex], flags: JSON_THROW_ON_ERROR));
        touch($manifestPath, $mtime);
        expect((new PrivateDocumentationLocator)->locate(requestedPath: null)->path)->toBe('index.html');

        touch($manifestPath, $mtime + 1);
        expect(fn () => $locator->locate(requestedPath: null))->toThrow(NotFoundHttpException::class);
        expect($locator->locate(requestedPath: 'other.html')->path)->toBe('other.html');

        File::put($manifestPath, json_encode([$article], flags: JSON_THROW_ON_ERROR));
        touch($manifestPath, $mtime + 1);

        expect(fn () => $locator->locate(requestedPath: 'other.html'))->toThrow(NotFoundHttpException::class);
    } finally {
        File::deleteDirectory($root);
    }
});
