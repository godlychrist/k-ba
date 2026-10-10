<?php

return [
    'import_since' => '2026-09-01',
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'gmail' => [
        'client_id' => env('GOOGLE_CLIENT_ID'), 'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', 'http://localhost:8000/auth/gmail/callback'),
        'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
        'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
    ],
    'outlook' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'), 'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect_uri' => env('MICROSOFT_REDIRECT_URI', 'http://localhost:8000/auth/outlook/callback'),
        'authorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
        'token' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
        'scope' => 'offline_access User.Read Mail.Read',
    ],
];
