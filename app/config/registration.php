<?php
return [
    'enabled' => env('REGISTRATION_ENABLED', false),
    'privacy_url' => env('REGISTRATION_PRIVACY_URL', ''),
    'imprint_url' => env('REGISTRATION_IMPRINT_URL', ''),
    'daily_limit' => 100,
    'keywords' => [
        'restaurant' => [
            'industry' => ['restaurant', 'gasthaus', 'gasthof', 'bistro', 'brasserie', 'pizzeria', 'trattoria', 'café', 'cafe', 'gastronomie'],
            'offering' => ['speisekarte', 'menü', 'menu', 'tischreservierung', 'küche', 'mittagstisch', 'speisen', 'dinner'],
        ],
        'hotel' => [
            'industry' => ['hotel', 'pension', 'resort', 'landhotel', 'boutiquehotel'],
            'offering' => ['zimmer', 'übernachtung', 'uebernachtung', 'unterkunft', 'check-in', 'frühstück', 'breakfast', 'rooms', 'accommodation'],
        ],
    ],
];
