<?php

namespace App\Support\Integrations;

enum Capability: string
{
    case Servers = 'servers';
    case CreateServers = 'create_servers';
    case Resize = 'resize';
    case Power = 'power';
    case Snapshots = 'snapshots';
    case DeleteServers = 'delete_servers';
    case Repositories = 'repositories';
    case PushDeploys = 'push_deploys';
    case Images = 'images';
    case Dns = 'dns';
    case PurgeCache = 'purge_cache';
    case Releases = 'releases';
    case Pages = 'pages';
    case Issues = 'issues';
    case Tailnet = 'tailnet';
    case Workflows = 'workflows';
    case ServerOsApi = 'serveros_api';
    case Metrics = 'metrics';
    case Dashboards = 'dashboards';
    case AskAi = 'ask_ai';
    case ServerFixes = 'server_fixes';
    case Readmes = 'readmes';
    case CodingAgents = 'coding_agents';
    case TokenUsage = 'token_usage';
    case BackupStorage = 'backup_storage';
    case ChannelAlerts = 'channel_alerts';
    case DeployNotices = 'deploy_notices';
    case AlertButtons = 'alert_buttons';
    case SlashCommand = 'slash_command';
    case ChatCommands = 'chat_commands';

    public function label(): string
    {
        return self::t(match ($this) {
            self::Servers => 'Link machines',
            self::CreateServers => 'Make servers',
            self::Resize => 'Resize',
            self::Power => 'Restart and power cycle',
            self::Snapshots => 'Snapshots',
            self::DeleteServers => 'Delete servers',
            self::Repositories => 'Deploy its projects',
            self::PushDeploys => 'Deploy each push',
            self::Images => 'Deploy its images',
            self::Dns => 'Point domains',
            self::PurgeCache => 'Purge cache',
            self::Releases => 'Releases for each deploy',
            self::Pages => 'Page whoever is on call',
            self::Issues => 'Issues for what breaks',
            self::Tailnet => 'Put machines on the tailnet',
            self::Workflows => 'Start its workflows',
            self::ServerOsApi => 'Call ServerOS from it',
            self::Metrics => 'Machine metrics',
            self::Dashboards => 'A dashboard for the workspace',
            self::AskAi => 'Ask AI on your key',
            self::ServerFixes => 'Server fixes',
            self::Readmes => 'Machine readmes',
            self::CodingAgents => 'Coding agents',
            self::TokenUsage => 'Token usage',
            self::BackupStorage => 'Store backups',
            self::ChannelAlerts => 'Alerts in a channel',
            self::DeployNotices => 'Deploy notices',
            self::AlertButtons => 'Act from the alert',
            self::SlashCommand => 'The /serveros command',
            self::ChatCommands => 'Slash commands',
        });
    }

    public function description(): string
    {
        return self::t(match ($this) {
            self::Servers => 'Matches its servers to your machines by IP address, and lists the ones that aren’t in ServerOS yet.',
            self::CreateServers => 'Makes a server that installs ServerOS as it starts, so it joins on its own.',
            self::Resize => 'Moves a linked machine to a bigger or smaller server type.',
            self::Power => 'Restarts a linked machine, or cuts its power and starts it again, even when its daemon is offline.',
            self::Snapshots => 'Takes, lists and deletes snapshots of a linked machine’s disk.',
            self::DeleteServers => 'Deletes a linked machine’s server for good.',
            self::Repositories => 'Lists its projects when you deploy, clones private ones and shows each deploy’s commit message.',
            self::PushDeploys => 'Adds a webhook to a project when one of its apps deploys each push, and takes it off again when none does.',
            self::Images => 'Lists its repositories and tags when you deploy, pulls private images with its login, and shows when an app’s tag has a newer image.',
            self::Dns => 'Adds or updates the DNS record for each app’s domain as it deploys, and removes the ones it added when the domain goes.',
            self::PurgeCache => 'Purges an app’s hostnames from Cloudflare’s cache after each deploy, or when you ask.',
            self::Releases => 'Makes a Sentry release with the commit each time an app deploys, marks it deployed, and marks the older one deployed again on a roll back. The Deploys card shows new issues since each one.',
            self::Pages => 'Triggers an alert when a monitor goes down, a machine stops reporting or an exploited critical CVE turns up, and resolves it when it’s fixed.',
            self::Issues => 'Opens an issue when a monitor stays down, a machine stays offline or a critical CVE turns up, comments as it changes and closes it when it’s fixed.',
            self::Tailnet => 'Installs Tailscale on a machine with a one-off key, matches each device to its machine and shows its tailnet address, and takes machines off again.',
            self::Workflows => 'A workflow step starts one of its workflows through its webhook with what happened, and can wait for its answer and use it in later steps.',
            self::ServerOsApi => 'Makes a starter workflow in it that calls the ServerOS API with a read-only key, and shows how its HTTP Request node calls ServerOS.',
            self::Metrics => 'Machine, service and monitor metrics, sent every minute or scraped by your own Prometheus.',
            self::Dashboards => 'Makes a dashboard of the workspace’s machines, services and monitors, and updates it when you ask.',
            self::AskAi => 'The assistant runs on your key when you pick it, and its chats can pick any of the key’s models.',
            self::ServerFixes => 'Looks into a problem on a machine with your model and proposes the steps to fix it.',
            self::Readmes => 'Writes each machine’s readme with your model.',
            self::CodingAgents => 'Agents and workflow agent steps get the key when they run, so they bill to your account.',
            self::TokenUsage => 'Counts the tokens each feature uses on the key, by model, for the last 30 days.',
            self::BackupStorage => 'Makes a private bucket for your backups with a key that can only reach it, sets how long backups stay, and shows how much is stored.',
            self::ChannelAlerts => 'Posts each alert to the channel you pick, and changes a monitor or machine alert to fixed when it’s back.',
            self::DeployNotices => 'Posts each deploy to the channel you pick, with a button to roll back to the version before.',
            self::AlertButtons => 'Check now, Restart the service behind a monitor and Mute 1 hour run as the person who presses them, when their role can manage machines.',
            self::SlashCommand => 'Shows what’s up and down, one machine, and deploys an app’s latest code after you confirm.',
            self::ChatCommands => '/status, /machine, /checknow and /mute in the chat: anyone who sees the alerts can read, and changes need a linked account whose role can manage machines.',
        });
    }

    /** @return array{key: string, label: string, description: string} */
    public function toArray(): array
    {
        return ['key' => $this->value, 'label' => $this->label(), 'description' => $this->description()];
    }

    private static function t(string $text): string
    {
        return is_string($line = __($text)) ? $line : $text;
    }
}
