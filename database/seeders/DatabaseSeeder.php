<?php

namespace Database\Seeders;

use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;
use Database\Seeders\Procurement\CrawlerProfileSeeder;
use Database\Seeders\Procurement\EisPlatformSeeder;
use Database\Seeders\Procurement\Okpd2NodeSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // PHP-роли зеркалятся в таблицу roles до назначения (idempotent, безопасно всегда).
        Artisan::call('guard:sync-roles');

        // Служебная площадка «Обучалка» (§1.3 root/document-contour.md, D91) — единственный
        // способ, которым площадка появляется в чистой БД; нужна и в prod (обучающий импорт
        // пишет platform_id этой строки), поэтому ДО local/testing-гейта, как AiRoutingSeeder.
        $this->call(PlatformSeeder::class);

        // Стартовая матрица роутинга LLM (P7.2, ТЗ 04 §3.1) — идемпотентный updateOrCreate, без
        // секретов (ключи только env, Implementation Rules P7.2). В ОТЛИЧИЕ от демо-аккаунтов
        // ниже — инфраструктурные данные пайплайна нужны и в prod (без активных маршрутов
        // LlmRouteResolver кидает RuntimeException), поэтому вызов — ДО local/testing-гейта.
        $this->call(AiRoutingSeeder::class);

        // Файловые промты (P8.1, D5/D17) — резолверу/оркестраторам НУЖЕН непустой
        // `llm_prompts` в prod так же, как маршрутам выше (PromptComposer иначе собирает
        // пустую системную часть) — та же логика «до local/testing-гейта», что AiRoutingSeeder.
        $this->call(PromptSeeder::class);

        // Площадка ЕИС + реестр fz44 с настройками детальной карточки (P4.5) — ДО
        // StatusMappingSeeder: тот заводит матрицу только когда реестр fz44 уже существует.
        $this->call(EisPlatformSeeder::class);

        // Стартовая матрица `prc_status_mappings` для реестра fz44 (DOMLANG P2.2, D17) — без неё
        // первый боевой прогон ЕИС отправит все закупки в unknown (`StatusMappingService::resolve()`
        // на незнакомой паре). Инфраструктурные данные, поэтому ДО local/testing-гейта, как выше.
        $this->call(StatusMappingSeeder::class);

        // Карта «код ОКПД2 → внутренний id НСИ» площадки eis (P2.1, D19) — ДО CrawlerProfileSeeder:
        // резолв okpd2Codes фильтра в P2.2+ опирается на неё.
        $this->call(Okpd2NodeSeeder::class);

        // Два профиля обхода fz44 — архивный (обучающий корпус) и fresh (выключен, D37), P4.5.
        // ПОСЛЕ StatusMappingSeeder — тот же порядок, что задокументирован research/05 §5.
        $this->call(CrawlerProfileSeeder::class);

        // Демо-аккаунты (в т.ч. суперадмин с wildcard) — ТОЛЬКО в local/testing.
        // На prod бутстрап суперадмина — через `php artisan guard:super-admin --user=`,
        // иначе db:seed на деплое создал бы бэкдор с предсказуемым паролем.
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $password = Hash::make(env('DEV_SEED_PASSWORD', 'password'));

        $accounts = [
            ['Super Admin', 'admin@agelto.local', SuperAdminRole::class],
            ['Operator', 'operator@agelto.local', OperatorRole::class],
            ['Analyst', 'analyst@agelto.local', AnalystRole::class],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            User::factory()
                ->create(['name' => $name, 'email' => $email, 'password' => $password])
                ->assignRole($role);
        }
    }
}
