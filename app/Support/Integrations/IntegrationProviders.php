<?php

namespace App\Support\Integrations;

use App\Support\Integrations\Ai\AnthropicProvider;
use App\Support\Integrations\Ai\OllamaProvider;
use App\Support\Integrations\Ai\OpenAiProvider;
use App\Support\Integrations\Ai\OpenRouterProvider;
use App\Support\Integrations\Aws\AwsProvider;
use App\Support\Integrations\Backblaze\BackblazeProvider;
use App\Support\Integrations\Cloudflare\CloudflareProvider;
use App\Support\Integrations\DigitalOcean\DigitalOceanProvider;
use App\Support\Integrations\Discord\DiscordProvider;
use App\Support\Integrations\DockerHub\DockerHubProvider;
use App\Support\Integrations\GitLab\GitLabProvider;
use App\Support\Integrations\Grafana\GrafanaProvider;
use App\Support\Integrations\Hetzner\HetznerProvider;
use App\Support\Integrations\Linear\LinearProvider;
use App\Support\Integrations\N8n\N8nProvider;
use App\Support\Integrations\OVHcloud\OVHcloudProvider;
use App\Support\Integrations\PagerDuty\PagerDutyProvider;
use App\Support\Integrations\Proxmox\ProxmoxProvider;
use App\Support\Integrations\R2\R2Provider;
use App\Support\Integrations\Sentry\SentryProvider;
use App\Support\Integrations\Slack\SlackProvider;
use App\Support\Integrations\Tailscale\TailscaleProvider;
use App\Support\Integrations\Teams\TeamsProvider;
use App\Support\Integrations\Vultr\VultrProvider;
use Illuminate\Container\Attributes\Singleton;
use InvalidArgumentException;

#[Singleton]
final class IntegrationProviders
{
    /** @var array<string, Provider> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new HetznerProvider);
        $this->register(new DigitalOceanProvider);
        $this->register(new VultrProvider);
        $this->register(new AwsProvider);
        $this->register(new OVHcloudProvider);
        $this->register(new ProxmoxProvider);
        $this->register(new CloudflareProvider);
        $this->register(new SentryProvider);
        $this->register(new PagerDutyProvider);
        $this->register(new LinearProvider);
        $this->register(new DiscordProvider);
        $this->register(new SlackProvider);
        $this->register(new TeamsProvider);
        $this->register(new GitLabProvider);
        $this->register(new DockerHubProvider);
        $this->register(new TailscaleProvider);
        $this->register(new BackblazeProvider);
        $this->register(new R2Provider);
        $this->register(new AnthropicProvider);
        $this->register(new OpenAiProvider);
        $this->register(new OpenRouterProvider);
        $this->register(new OllamaProvider);
        $this->register(new N8nProvider);
        $this->register(new GrafanaProvider);
    }

    public function register(Provider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function find(string $key): ?Provider
    {
        return $this->providers[$key] ?? null;
    }

    public function get(string $key): Provider
    {
        return $this->providers[$key] ?? throw new InvalidArgumentException("No integration provider is built for {$key}.");
    }

    /** @return array<string, Provider> */
    public function all(): array
    {
        return $this->providers;
    }
}
