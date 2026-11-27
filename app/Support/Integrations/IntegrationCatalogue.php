<?php

namespace App\Support\Integrations;

/**
 * @phpstan-type Category array{key: string, tab: string, title: string, subtitle: string, icon: string}
 * @phpstan-type Entry array{key: string, name: string, category: string, logo: string|null, initials: string|null, description: string, tags: list<string>}
 */
final class IntegrationCatalogue
{
    /** @return list<Category> */
    public static function categories(): array
    {
        return [
            ['key' => 'developer', 'tab' => self::t('Developer'), 'title' => self::t('Developer'), 'subtitle' => self::t('Code, images and errors'), 'icon' => 'code-fill'],
            ['key' => 'communication', 'tab' => self::t('Communication'), 'title' => self::t('Communication and incidents'), 'subtitle' => self::t('Alerts, approvals and on-call'), 'icon' => 'user-fill'],
            ['key' => 'cloud', 'tab' => self::t('Cloud'), 'title' => self::t('Cloud providers'), 'subtitle' => self::t('Make, import and manage servers without leaving ServerOS'), 'icon' => 'machines-fill'],
            ['key' => 'ai', 'tab' => self::t('AI and automation'), 'title' => self::t('AI and automation'), 'subtitle' => self::t('Your own models, automations and dashboards'), 'icon' => 'sparkle-fill'],
        ];
    }

    /** @return list<Entry> */
    public static function all(): array
    {
        $entry = fn (string $key, string $name, string $category, ?string $logo, string $description, array $tags, ?string $initials = null): array => [
            'key' => $key,
            'name' => $name,
            'category' => $category,
            'logo' => $logo,
            'initials' => $initials,
            'description' => $description,
            'tags' => array_values(array_map(fn (string $tag) => self::t($tag), $tags)),
        ];

        return [
            $entry('github', 'GitHub', 'developer', 'github', self::t('Deploy repositories, preview environments, deploy checks and automatic redeploys.'), ['Deploys', 'Previews', 'Checks']),
            $entry('gitlab', 'GitLab', 'developer', 'gitlab', self::t('Deploy projects from gitlab.com or your own GitLab, private ones too, and redeploy on every push.'), ['Deploys', 'Webhooks']),
            $entry('cloudflare', 'Cloudflare', 'developer', 'cloudflare', self::t('Points your apps’ domains at their machines and purges the cache after each deploy.'), ['DNS', 'Cache']),
            $entry('docker-hub', 'Docker Hub', 'developer', 'docker', self::t('Deploy your images, private ones too, redeploy when a new one is pushed, and see when an app is behind its tag.'), ['Deploys', 'Images', 'Webhooks']),
            $entry('sentry', 'Sentry', 'developer', 'sentry', self::t('A release for each deploy with its commit, rollbacks marked, and new issues on the Deploys card.'), ['Releases', 'Deploys']),

            $entry('slack', 'Slack', 'communication', 'slack', self::t('Alerts with Check now, Restart and Mute buttons that change to fixed when it’s back, deploys with Roll back, and /serveros in any channel.'), ['Alerts', 'Deploys', 'Commands']),
            $entry('discord', 'Discord', 'communication', 'discord', self::t('Alerts with Check now, Restart and Mute buttons, edited when they’re fixed, deploy notices, and /status, /machine and /checknow in your server.'), ['Alerts', 'Deploys', 'Commands']),
            $entry('telegram', 'Telegram', 'communication', 'telegram', self::t('Alerts in a Telegram chat from your own bot, with buttons to check, restart and mute, and /status, /machine and /checknow from the chat.'), ['Alerts', 'Commands']),
            $entry('linear', 'Linear', 'communication', 'linear', self::t('Opens an issue when something stays broken, comments as it changes and closes it when it’s fixed.'), ['Issues', 'Incidents']),
            $entry('pagerduty', 'PagerDuty', 'communication', 'pagerduty', self::t('Pages whoever is on call when a monitor or machine goes down, and resolves it when it’s back.'), ['Incidents', 'On call']),
            $entry('teams', 'Microsoft Teams', 'communication', null, self::t('Alert and deploy cards in a Teams channel through a Teams workflow, each with a link to it in ServerOS.'), ['Alerts', 'Deploys'], 'MT'),

            $entry('hetzner', 'Hetzner', 'cloud', 'hetzner', self::t('Make, import and resize servers, restart them and take snapshots.'), ['Machines', 'Snapshots']),
            $entry('digitalocean', 'DigitalOcean', 'cloud', 'digitalocean', self::t('Make, import and resize droplets, restart them and take snapshots.'), ['Machines', 'Snapshots']),
            $entry('aws', 'AWS', 'cloud', 'aws', self::t('Make, import and resize EC2 instances in the regions you pick, restart them and take EBS snapshots.'), ['Machines', 'Snapshots']),
            $entry('ovhcloud', 'OVHcloud', 'cloud', 'ovhcloud', self::t('Make, import and resize Public Cloud instances, restart them and take snapshots. Dedicated servers are linked too.'), ['Machines', 'Snapshots']),
            $entry('vultr', 'Vultr', 'cloud', 'vultr', self::t('Make, import and upgrade servers, restart them and take snapshots.'), ['Machines', 'Snapshots']),
            $entry('proxmox', 'Proxmox', 'cloud', 'proxmox', self::t('Clone VMs from your templates with ServerOS on, import and resize them, restart them and take snapshots. Containers are listed read only.'), ['Machines', 'Snapshots']),
            $entry('tailscale', 'Tailscale', 'cloud', 'tailscale', self::t('Put machines on your tailnet with a one-off key, see each one’s tailnet address and take them off again.'), ['Network', 'Machines']),
            $entry('backblaze', 'Backblaze B2', 'cloud', 'backblaze', self::t('Make a private bucket for your backups with a key that can only write there, and see how much is stored and what it costs.'), ['Backups', 'Storage']),
            $entry('r2', 'Cloudflare R2', 'cloud', 'cloudflare', self::t('Make an R2 bucket for your backups with a key for just that bucket, using your Cloudflare token or a new one.'), ['Backups', 'Storage']),

            $entry('anthropic', 'Anthropic', 'ai', 'anthropic', self::t('Claude for Ask AI, server fixes, machine readmes and Claude Code agents, on your own key, with its token use.'), ['Ask AI', 'Agents', 'Usage']),
            $entry('openai', 'OpenAI', 'ai', 'openai', self::t('GPT models for Ask AI, server fixes, machine readmes and Codex agents, on your own key, with its token use.'), ['Ask AI', 'Agents', 'Usage']),
            $entry('openrouter', 'OpenRouter', 'ai', 'openrouter', self::t('Hundreds of models on one key for Ask AI, server fixes, readmes and OpenCode agents, with its credit left.'), ['Ask AI', 'Agents', 'Usage']),
            $entry('ollama', 'Ollama', 'ai', 'ollama', self::t('Models you host yourself for Ask AI, server fixes, readmes and OpenCode agents.'), ['Ask AI', 'Agents', 'Self-hosted']),
            $entry('n8n', 'n8n', 'ai', 'n8n', self::t('Start n8n workflows from a ServerOS workflow and use their answer, and call the ServerOS API from n8n.'), ['Workflows', 'API']),
            $entry('grafana', 'Grafana', 'ai', 'grafana', self::t('Machine, service and monitor metrics in Grafana Cloud or your own Prometheus, with a dashboard made for you.'), ['Metrics', 'Dashboards']),
        ];
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
