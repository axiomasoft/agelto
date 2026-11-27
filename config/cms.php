<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Admin email
    |--------------------------------------------------------------------------
    |
    | This email is used to create the initial administrator user.
    | It is also used to log in to the admin panel.
    |
    */

    'admin_email' => env(key: 'ADMIN_EMAIL', default: ''),

    'demo_seed_password' => env(key: 'DEV_SEED_PASSWORD', default: ''),
];
