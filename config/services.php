<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),

        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
    ],

    'discord' => [
        'changelog_webhook' => env('DISCORD_CHANGELOG_WEBHOOK'),
        // The public security feed: notable new CVEs (security:cves).
        'security_webhook' => env('DISCORD_SECURITY_WEBHOOK'),
        // The Discord app workspaces add to their servers for alerts with buttons and slash commands (docs/integrations.md says how to make it).
        'application_id' => env('DISCORD_APPLICATION_ID'),
        'public_key' => env('DISCORD_PUBLIC_KEY'),
        'bot_token' => env('DISCORD_BOT_TOKEN'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
    ],

    // Whether a workspace's Telegram bot gets a webhook to this panel, for buttons and commands. Telegram needs a public https address.
    'telegram' => [
        'webhooks' => (bool) env('TELEGRAM_WEBHOOKS', true),
    ],

    // NVD's CVE API. Works without a key (5 requests in 30 seconds); a free
    // key from nvd.nist.gov/developers/request-an-api-key allows 50.
    'nvd' => [
        'key' => env('NVD_API_KEY'),
    ],

    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL') ?: 'openai/gpt-oss-120b',
        // Used for Ask AI messages with images when the main model can't see them.
        'vision_model' => env('GROQ_VISION_MODEL') ?: 'qwen/qwen3.8-27b',
        'agent_queue' => env('AGENT_QUEUE') ?: 'default',

        'translate_key' => env('GROQ_TRANSLATE_KEY') ?: env('GROQ_API_KEY'),
        'translate_model' => env('GROQ_TRANSLATE_MODEL') ?: 'openai/gpt-oss-120b',
    ],

    // How OpenRouter names this panel on its app pages (openrouter.ai/docs/app-attribution).
    // The address defaults to APP_URL and the title to APP_NAME.
    'openrouter' => [
        'referer' => env('OPENROUTER_REFERER'),
        'title' => env('OPENROUTER_TITLE'),
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

    // The Slack app workspaces connect their Slack through (docs/integrations.md has its manifest).
    'slack_app' => [
        'client_id' => env('SLACK_CLIENT_ID'),
        'client_secret' => env('SLACK_CLIENT_SECRET'),
        'signing_secret' => env('SLACK_SIGNING_SECRET'),
    ],

    'github' => [
        'token' => env('GITHUB_TOKEN'),
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => '/auth/github/callback',
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'cloudflare' => [
        'token' => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'cname_target' => env('CLOUDFLARE_CNAME_TARGET', 'customers.'.env('APP_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST))),
    ],

    'github_app' => [
        'id' => env('GITHUB_APP_ID'),
        'slug' => env('GITHUB_APP_SLUG'),
        'client_id' => env('GITHUB_APP_CLIENT_ID') ?: env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_APP_CLIENT_SECRET') ?: env('GITHUB_CLIENT_SECRET'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),

        'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => '/auth/google/callback',
        'identity_button' => (bool) env('GOOGLE_IDENTITY_BUTTON', false),
    ],

    'ntfy' => [
        'server' => env('NTFY_SERVER', 'https://ntfy.sh'),
        'topic' => env('NTFY_TOPIC'),
        'token' => env('NTFY_TOKEN'),
    ],

    'seline' => [
        'token' => env('SELINE_TOKEN'),
    ],

    // The image "Add to tailnet" runs on a machine, and how long its one-off key lasts.
    'tailscale' => [
        'image' => env('TAILSCALE_IMAGE', 'tailscale/tailscale:stable'),
        'key_minutes' => (int) env('TAILSCALE_KEY_MINUTES', 15),
    ],

    // Published storage prices in US dollars, for the cost estimate on a backup bucket.
    'backblaze' => [
        'price_per_tb_month' => (float) env('BACKBLAZE_PRICE_PER_TB_MONTH', 6.95),
    ],

    'r2' => [
        'price_per_gb_month' => (float) env('R2_PRICE_PER_GB_MONTH', 0.015),
    ],

];
