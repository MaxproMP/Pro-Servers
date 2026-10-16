<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'public_url' => env('PUBLIC_URL', env('APP_URL', 'http://localhost')),

    'ceo_email' => env('CEO_EMAIL', 'rodasmaximo51@gmail.com'),

    // Funciones premium (Mine AI, gestor de archivos): plan Oro o superior.
    'planes_premium' => ['oro', 'diamante', 'netherite', 'ghost-warrior'],

    'mercadopago' => [
        'token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'webhook_secret' => env('MP_WEBHOOK_SECRET'),
        'tasa_ars' => env('MP_TASA_ARS'), // pesos por dólar que se cobran en Mercado Pago
    ],

    'astropay' => [
        'api_url' => env('ASTROPAY_API_URL'),
        'client_id' => env('ASTROPAY_CLIENT_ID'),
        'secret' => env('ASTROPAY_SECRET'),
    ],

    // Firma HMAC de webhooks de pago. Sin secret configurado, todo webhook se rechaza.
    'webhooks' => [
        'secret' => env('WEBHOOK_SECRET'),
    ],

    // Prueba gratis: una sola por cuenta, sin cobro, solo en planes de entrada.
    'trial' => [
        'dias' => 15,
        'planes' => ['redstone', 'hierro', 'cobre'],
    ],

    'daemon' => [
        'secret' => env('NODE_SECRET_KEY'),
    ],


    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
    ],

];
