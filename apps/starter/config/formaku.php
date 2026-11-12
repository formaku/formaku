<?php

use App\Support\FormakuAccess;

return [
    'public_routes' => true,
    'sitemap' => env('FORMAKU_SITEMAP', true),
    'authorize' => [FormakuAccess::class, 'allows'],
    'staff' => [FormakuAccess::class, 'staff'],
    'roles' => [
        'admin' => 'Administrator',
        'editor' => 'Redaktor',
        'publisher' => 'Publikujący',
        'customer' => 'Klient',
    ],
];
