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

    'openai' => [
        // OpenRouter is OpenAI-compatible: set OPENAI_BASE_URL + your sk-or-v1 key.
        'key' => env('OPENAI_API_KEY'),
        'base_url' => rtrim(env('OPENAI_BASE_URL', 'https://openrouter.ai/api/v1'), '/'),
        'vision_model' => env('OPENAI_VISION_MODEL', 'openai/gpt-4o'),
        'speech_model' => env('OPENAI_SPEECH_MODEL', ''),
        'transcribe_model' => env('OPENAI_TRANSCRIBE_MODEL', ''),
        'site_url' => env('OPENAI_SITE_URL', env('APP_URL')),
        'site_name' => env('OPENAI_SITE_NAME', env('APP_NAME', 'OCR App')),
    ],

    'ocr' => [
        'api_key' => env('OCR_API_KEY'),
    ],

];
