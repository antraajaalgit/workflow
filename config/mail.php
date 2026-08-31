<?php

return [
    'default' => env('MAIL_MAILER', 'log'),
    'mailers' => [
        'log' => ['transport' => 'log', 'channel' => env('MAIL_LOG_CHANNEL')],
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
        ],
         'chat_smtp' => [
        'transport' => 'smtp',
        'scheme' => env('CHAT_MAIL_SCHEME', 'smtps'),
        'host' => env('CHAT_MAIL_HOST', 'smtp.hostinger.com'),
        'port' => env('CHAT_MAIL_PORT', 465),
        'username' => env('CHAT_MAIL_USERNAME'),
        'password' => env('CHAT_MAIL_PASSWORD'),
        'timeout' => null,
    ],
    ],
    'chat_from' => [
        'address' => env('CHAT_MAIL_FROM_ADDRESS', env('CHAT_MAIL_USERNAME', 'hello@antraajaal.com')),
        'name' => env('CHAT_MAIL_FROM_NAME', 'Karya by Antrajaal'),
    ],
    'from' => ['address' => env('MAIL_FROM_ADDRESS', 'hello@antraajaal.com'), 'name' => env('MAIL_FROM_NAME', 'Karya by Antrajaal')],
];
