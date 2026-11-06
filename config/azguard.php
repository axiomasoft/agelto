<?php

declare(strict_types=1);

use App\Guards\Admin\AdminGuardPanelProvider;
use AzGuard\Storage\Models\Permission;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;

return [

    'panels' => [
        'providers' => [
            AdminGuardPanelProvider::class,
        ],
    ],

    'defaults' => [

        'models' => [
            'role_grant' => RoleGrant::class,
            'permission_grant' => PermissionGrant::class,
            'permission' => Permission::class,
        ],

        'resource_prefix' => true,

        'cache' => [
            'store' => env('AZ_GUARD_CACHE_STORE'),
            'ttl' => 300,
            'generation' => 1,
        ],

        'consistency' => [
            'reads' => 'primary',
            'state_refresh' => 'request',
        ],

        'trace_decisions' => false,

        'tenants' => ['resolvers' => []],

        'scopes' => ['resolvers' => []],

    ],

    'gate' => [
        'enabled' => true,
    ],

    'decision_sets' => [
        'max_subjects' => 500,
    ],

    'schedule' => [
        'enabled' => false,
        'prune_expired' => null,
    ],

    'cache' => [
        'max_memo_entries' => 1024,
        'max_state_entries' => 1024,
        'namespace' => env('AZGUARD_CACHE_NAMESPACE', 'agelto'),
        'signing_key' => env('AZGUARD_CACHE_SIGNING_KEY'),
        'key_id' => env('AZGUARD_CACHE_KEY_ID', 'active'),
        'previous_keys' => [],
    ],

    'catalog' => [
        'build_id' => env('AZGUARD_BUILD_ID'),
        'cache_path' => null,
    ],

    'storages' => [
        'default' => [
            'connection' => env('AZGUARD_DB_CONNECTION'),
            'table_prefix' => 'azg_',
            'host_keys' => null,
        ],
    ],

    'ids' => ['host_keys' => 'string'],

    'sources' => [],

    'discovery' => [
        'permissions' => 'Permissions',
        'policies' => 'Policies',
        'roles' => 'Roles',
        'scopes' => 'Scopes',
        'abilities' => 'Abilities',
        'queries' => 'Queries',
        'shared' => 'Shared',
    ],

];
