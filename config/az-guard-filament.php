<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use Filament\Resources\Pages\CreateRecord;

return [
    /*
    |--------------------------------------------------------------------------
    | Linked AzGuard panel
    |--------------------------------------------------------------------------
    |
    | The AzGuard panel id whose permission catalog backs this Filament panel.
    | Discovered Filament resources/pages register their permissions into this
    | panel, and resource authorization is checked against it.
    |
    */
    'panel' => 'admin',

    /*
    |--------------------------------------------------------------------------
    | Enforcement
    |--------------------------------------------------------------------------
    |
    | When true, the plugin authorizes every discovered resource against its
    | generated permission with zero code in the resources: it disables
    | Filament's policy-existence shortcut and answers resource Gate checks
    | from the user's AzGuard permissions. Set false to manage authorization
    | yourself (e.g. with generated policies).
    |
    */
    'enforce' => true,

    /*
    |--------------------------------------------------------------------------
    | Permission source
    |--------------------------------------------------------------------------
    |
    | Discovered keys are always registered in the catalog (so they show up in
    | the Role UI and can be granted). The source decides how access is
    | *enforced* and what code, if any, is generated:
    |   'database' — the runtime gate enforces; nothing is generated.
    |   'enum'     — `guard:filament:generate` writes a permission enum per
    |                resource (typed, IDE-friendly), still enforced by the gate.
    |   'policy'   — `guard:filament:generate` writes a Laravel policy per
    |                resource; Filament's native authorization enforces them and
    |                the runtime gate steps aside.
    |
    */
    'source' => 'enum',

    /*
    |--------------------------------------------------------------------------
    | Abilities generated per resource
    |--------------------------------------------------------------------------
    |
    | Each ability becomes its own permission key. Trim this list to scope
    | down, or add custom abilities.
    |
    */
    'abilities' => [
        'view_any',
        'view',
        'create',
        'update',
        'delete',
        'restore',
        'force_delete',
        'replicate',
        'reorder',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages and widgets
    |--------------------------------------------------------------------------
    |
    | Discovered custom pages/widgets get a catalogued `{panel}.{page}.view` /
    | `{panel}.{widget}.view` permission, but — unlike resources — it is NOT
    | enforced automatically: Filament routes pages/widgets through their own
    | static canAccess()/canView(), which never touch the Gate. Add
    | AzGuard\Filament\Concerns\HasAzGuardPage / HasAzGuardWidget to a page or
    | widget class to make it actually enforce the permission. Hiding the nav
    | link (e.g. via shouldRegisterNavigation()) is NOT access control — the
    | URL stays reachable without the trait.
    |
    */
    'pages' => [
        'ability' => 'view',
    ],

    'widgets' => [
        'ability' => 'view',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission key scheme
    |--------------------------------------------------------------------------
    |
    | Template for a resolved permission key. Placeholders: {panel}, {resource},
    | {ability}. `case` formats the {resource} segment (snake|kebab|camel|none).
    |
    */
    'key' => '{panel}.{resource}.{ability}',
    'case' => 'snake',

    /*
    |--------------------------------------------------------------------------
    | Discovery exclusions
    |--------------------------------------------------------------------------
    |
    | Fully-qualified resource/page/widget class names to skip during
    | discovery and generation.
    |
    | PromptResource: excluded so ResourceGate::check() defers (returns null)
    | for the Prompt model — the enum-RBAC Gate::before short-circuit never
    | fires for it, letting the native Gate::policy() resolve to PromptPolicy
    | (app/Guards/Admin/Policies/PromptPolicy.php), which enforces the
    | record-specific `is_protected` read-only rule on top of the coarse
    | PromptPermission grant. Coarse permissions/Role UI are untouched — that
    | catalog comes from AdminGuardPanelProvider::discoverPermissionEnums(),
    | not from this Filament-plugin discovery (see EST-AI plan D44).
    |
    */
    'exclude' => [
        'resources' => [
            PromptResource::class,
            AnalysisResource::class,
        ],
        'pages' => [
            CreateRecord::class,
        ],
        'widgets' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | User label column
    |--------------------------------------------------------------------------
    |
    | Column name used to display user names in the Direct Grant and Role UIs.
    | Customize this if your user model uses a different column (e.g., 'email'
    | instead of 'name').
    |
    */
    'user_label_column' => 'name',

    'labels' => [
        'navigation_group' => 'Доступ и безопасность',
        'role' => [
            'navigation' => 'Роли пользователей',
            'singular' => 'Роль пользователя',
            'plural' => 'Роли пользователей',
            'name' => 'Название',
            'level' => 'Уровень приоритета',
            'level_help' => 'Чем выше значение, тем выше приоритет при объединении прав.',
            'code_role' => 'Роль управляется PHP-классом',
            'code_role_help' => 'Включите, если права роли определяются PHP-классом, а не базой данных.',
            'class_name' => 'Полное имя PHP-класса',
            'class_name_placeholder' => 'App\\Roles\\EditorRole',
            'class_name_help' => 'Полное имя класса, который определяет права.',
            'code_role_info' => 'Права этой роли определяются PHP-классом. Вкладка «Права» скрыта.',
            'id' => 'ID',
            'type' => 'Тип',
            'type_code' => 'Роль из кода',
            'type_custom' => 'Настраиваемая роль',
            'permissions_count' => 'Права из БД',
            'created_at' => 'Создана',
            'type_filter' => 'Тип роли',
            'all' => 'Все',
            'code_roles' => 'Роли из кода',
            'custom_roles' => 'Настраиваемые роли',
            'permissions' => 'Права',
            'permission' => 'Право',
            'permission_key' => 'Ключ права',
            'edit_permissions' => 'Изменить права',
            'panel' => 'Панель',
            'panel_heading' => 'Панель: :panel',
            'users' => 'Пользователи',
            'user' => 'Пользователь',
            'email' => 'Электронная почта',
            'assign_role' => 'Назначить роль',
        ],
        'direct_grant' => [
            'navigation' => 'Персональные права',
            'singular' => 'Персональное право',
            'plural' => 'Персональные права',
            'user' => 'Пользователь',
            'panel' => 'Панель',
            'permission' => 'Право',
            'select_panel_first' => 'Сначала выберите панель.',
            'valid_until' => 'Действует до',
            'valid_until_help' => 'Оставьте пустым, если срок действия не ограничен.',
            'never' => 'Бессрочно',
            'issued' => 'Выдано',
            'active_only' => 'Только действующие',
            'revoke' => 'Отозвать',
            'revoke_selected' => 'Отозвать выбранные',
            'issue' => 'Выдать право',
        ],
        'doctor' => [
            'navigation' => 'Проверка доступа',
            'title' => 'Проверка доступа AzGuard',
            'passed' => 'Все проверки пройдены',
            'abilities' => 'Права',
            'warnings' => 'Предупреждения',
            'errors' => 'Ошибки',
            'registered_abilities' => 'Зарегистрированные права (:count)',
            'panel' => 'Панель',
            'ability' => 'Право',
            'handler' => 'Политика::метод',
            'empty' => 'Зарегистрированных прав нет. Проверьте basePath и namespace.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Super admin
    |--------------------------------------------------------------------------
    |
    | Role name that bypasses every resource check (null = rely on the panel's
    | wildcard role, e.g. SuperAdminRole).
    |
    */
    'super_admin' => null,

    /*
    |--------------------------------------------------------------------------
    | Code generation targets (source = enum | policy)
    |--------------------------------------------------------------------------
    */
    'generation' => [
        'enum_namespace' => 'App\\Guards\\Admin\\Permissions',
        'enum_path' => 'app/Guards/Admin/Permissions',
        'policy_namespace' => 'App\\Policies',
        'policy_path' => 'app/Policies',
    ],
];
