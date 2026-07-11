<?php

declare(strict_types=1);

return [
    'site_name' => 'The Space',
    'timezone' => 'UTC',
    'db' => [
        'host' => 'localhost',
        'name' => 'vtutopup_reservation',
        'user' => 'vtutopup_reservation',
        'pass' => 'Adamusani141@',
        'charset' => 'utf8mb4',
    ],
    'smtp' => [
        'host' => 'mail.vtutopup.com.ng',
        'port' => 465,
        'username' => 'reservation@vtutopup.com.ng',
        'password' => 'Adamusani141@',
        'secure' => 'ssl',
        'from_email' => 'reservation@vtutopup.com.ng',
        'from_name' => 'The Space Reservation',
        'admin_email' => 'adamuwudiri141@gmail.com',
    ],
    'admin' => [
        'username' => 'admin',
        'password' => 'ChangeThisAdminPassword123!',
    ],
    'seed_dates' => [
        '2026-07-01',
        '2026-07-02',
        '2026-07-03',
        '2026-07-04',
        '2026-07-05',
        '2026-07-06',
        '2026-07-07',
    ],
];
