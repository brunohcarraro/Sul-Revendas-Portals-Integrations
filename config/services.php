<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'integration' => [
        'token' => env('INTEGRATION_API_TOKEN'),
    ],
    
    'webmotors' => [
        'enabled' => env('WEBMOTORS_ENABLED', false),
        'environment' => env('WEBMOTORS_ENV', 'homologation'),
        'client_id' => env('WEBMOTORS_CLIENT_ID'),
        'client_secret' => env('WEBMOTORS_CLIENT_SECRET'),
        'hash_autenticacao' => env('WEBMOTORS_HASH_AUTENTICACAO'),
        'urls' => [
            'homologation' => 'https://hlg-webmotors.sensedia.com',
            'production' => 'https://api-webmotors.sensedia.com',
            'soap_wsdl' => 'https://integracao.webmotors.com.br/wsEstoqueRevendedorWebMotors.asmx?wsdl',
            'soap_endpoint' => 'https://integracao.webmotors.com.br/wsEstoqueRevendedorWebMotors.asmx',
        ],
        'login' => [
            'email' => env('WEBMOTORS_LOGIN_EMAIL'),
            'password' => env('WEBMOTORS_LOGIN_PASSWORD'),
            'cnpj' => env('WEBMOTORS_CNPJ'),
        ],
    ],

    'olx' => [
        'enabled' => env('OLX_ENABLED', false),
        'client_id' => env('OLX_CLIENT_ID'),
        'client_secret' => env('OLX_CLIENT_SECRET'),
        'redirect_uri' => env('OLX_REDIRECT_URI'),
        'access_token' => env('OLX_ACCESS_TOKEN'),
        'refresh_token' => env('OLX_REFRESH_TOKEN'),
        'urls' => [
            'api' => 'https://apps.olx.com.br',
            'auth' => 'https://auth.olx.com.br',
        ],
        'account' => [
            'email' => env('OLX_ACCOUNT_EMAIL'),
            'password' => env('OLX_ACCOUNT_PASSWORD'),
        ],
        'categories' => [
            'cars' => 2020,
            'motorcycles' => 2060,
            'trucks' => 2080,
        ],
    ],

    'mercadolivre' => [
        'enabled' => env('MERCADOLIVRE_ENABLED', false),
        'app_id' => env('MERCADOLIVRE_APP_ID'),
        'client_secret' => env('MERCADOLIVRE_CLIENT_SECRET'),
        'redirect_uri' => env('MERCADOLIVRE_REDIRECT_URI'),
        'access_token' => env('MERCADOLIVRE_ACCESS_TOKEN'),
        'refresh_token' => env('MERCADOLIVRE_REFRESH_TOKEN'),
        'user_id' => env('MERCADOLIVRE_USER_ID'),
        'token_expires_at' => env('MERCADOLIVRE_TOKEN_EXPIRES_AT'),
        'token_expires_in' => 21600, // 6 hours
        'urls' => [
            'api' => 'https://api.mercadolibre.com',
            'auth' => 'https://auth.mercadolivre.com.br',
        ],
        'site_id' => 'MLB',
        'categories' => [
            'cars' => 'MLB1744',
            'motorcycles' => 'MLB1051',
            'trucks' => 'MLB1766',
        ],
    ],

    'icarros' => [
        'enabled' => env('ICARROS_ENABLED', false),
        'client_id' => env('ICARROS_CLIENT_ID'),
        'client_secret' => env('ICARROS_CLIENT_SECRET'),
        'access_token' => env('ICARROS_ACCESS_TOKEN'),
        'refresh_token' => env('ICARROS_REFRESH_TOKEN'),
        'dealer_id' => env('ICARROS_DEALER_ID'),
        'token_expires_at' => env('ICARROS_TOKEN_EXPIRES_AT'),
        'source_urls' => [
            'https://sulrevendas.com.br/painel/integracao-icarros',
            'https://sulrevendas.com.br/painel/integrações',
            'https://sulrevendas.com.br',
        ],
        'urls' => [
            'api' => 'https://core-api.icarros.com.br',
            'auth' => 'https://accounts.icarros.com/auth/realms/icarros/protocol/openid-connect',
        ],
        'token_expires_in' => 300, // 5 minutes
        'refresh_expires_in' => 5184000, // 60 days
    ],
];
