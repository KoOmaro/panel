<?php

namespace App\Support\Integrations\Chat;

use App\Models\Workspace;

/**
 * One alert on its way to the workspace's chats, with what it's about. The
 * thread ties a problem to the alert that says it's fixed (monitor:12 down,
 * then up), so a chat can edit the first message instead of posting again.
 */
final readonly class ChatAlert
{
    /** What each problem's thread is called, by the event that opens it. */
    private const array OPENS = [
        'monitor.down' => 'monitor',
        'machine.offline' => 'machine',
        'machine.memory' => 'memory',
        'service.down' => 'service',
        'service.crash_loop' => 'service',
        'deploy.failed' => 'deploy',
        'backup.failed' => 'backup',
    ];

    /** The event that says each thread is fixed. */
    private const array RESOLVES = [
        'monitor.up' => 'monitor',
        'machine.online' => 'machine',
        'machine.memory_ok' => 'memory',
        'service.up' => 'service',
        'deploy.recovered' => 'deploy',
        'backup.recovered' => 'backup',
    ];

    public const string DEPLOY_NOTICE = 'deploy.finished';

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $extra  what the alert is about (monitor, machine, service, job_id), as MonitorAlerts::notify gets it
     */
    public function __construct(
        public Workspace $workspace,
        public string $event,
        public string $subject,
        public array $lines,
        public string $url,
        public bool $bad,
        public string $actionLabel,
        public array $extra = [],
        private ?string $plainText = null,
        public bool $editOnly = false,
    ) {}

    /**
     * The alert that a problem is fixed, for chats only, while its alerts are
     * muted: the open message is edited, and nothing new is posted.
     */
    public function quietly(): self
    {
        return new self($this->workspace, $this->event, $this->subject, $this->lines, $this->url, $this->bad, $this->actionLabel, $this->extra, $this->plainText, true);
    }

    /**
     * An alert that arrives as one line of text (VulnerabilityAlerts).
     *
     * @param  array<string, mixed>  $extra
     */
    public static function plain(Workspace $workspace, string $event, string $text, string $url, array $extra = []): self
    {
        return new self($workspace, $event, $text, [], $url, true, 'Open it', $extra, $text);
    }

    /** The alert as one line, the way the webhook and Telegram have always had it. */
    public function text(): string
    {
        return $this->plainText ?? $this->subject.': '.implode(' ', $this->lines).' '.$this->url;
    }

    public function isDeployNotice(): bool
    {
        return $this->event === self::DEPLOY_NOTICE;
    }

    public function isDeploy(): bool
    {
        return str_starts_with($this->event, 'deploy.');
    }

    public function opens(): bool
    {
        return isset(self::OPENS[$this->event]) && $this->thread() !== null;
    }

    public function resolves(): bool
    {
        return isset(self::RESOLVES[$this->event]) && $this->thread() !== null;
    }

    public function thread(): ?string
    {
        $kind = self::OPENS[$this->event] ?? self::RESOLVES[$this->event] ?? null;
        $machine = $this->machineId();

        return match ($kind) {
            'monitor' => ($id = $this->monitorId()) === null ? null : "monitor:{$id}",
            'machine', 'memory' => $machine === null ? null : "{$kind}:{$machine}",
            'service' => $machine === null || ($key = $this->serviceKey()) === null ? null : "service:{$machine}:{$key}",
            'deploy', 'backup' => $machine === null || ! is_string($this->extra['service'] ?? null) ? null : "{$kind}:{$machine}:{$this->extra['service']}",
            default => null,
        };
    }

    public function monitorId(): ?int
    {
        $id = $this->extra['monitor']['id'] ?? null;

        return is_int($id) ? $id : null;
    }

    public function machineId(): ?int
    {
        $id = $this->extra['machine']['id'] ?? null;

        return is_int($id) ? $id : null;
    }

    public function serviceKey(): ?string
    {
        $key = $this->extra['service']['key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function serviceName(): ?string
    {
        $name = $this->extra['service']['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $this->serviceKey();
    }

    /**
     * The buttons this alert gets, besides Open: only while something is
     * wrong, and only ones that act on what it's about.
     *
     * @return list<ChatAction>
     */
    public function actions(): array
    {
        if (! $this->bad) {
            return [];
        }

        $monitor = $this->monitorId() !== null;
        $offline = $this->event === 'machine.offline' && $this->machineId() !== null;

        return array_values(array_filter([
            ($monitor && in_array($this->event, ['monitor.down', 'certificate.expiring'], true)) || $offline ? ChatAction::CheckNow : null,
            $this->serviceKey() !== null && $this->machineId() !== null && in_array($this->event, ['service.down', 'service.crash_loop', 'monitor.down'], true) ? ChatAction::Restart : null,
            ($monitor && $this->event === 'monitor.down') || $offline ? ChatAction::Mute : null,
        ]));
    }

    /** @return array<string, int|string> what the buttons act on, kept with the message */
    public function context(): array
    {
        return array_filter([
            'monitor' => $this->monitorId(),
            'machine' => $this->machineId(),
            'service' => $this->serviceKey(),
            'service_name' => $this->serviceName(),
        ], fn ($value) => $value !== null);
    }

    public function label(ChatAction $action): string
    {
        return match ($action) {
            ChatAction::CheckNow => 'Check now',
            ChatAction::Restart => 'Restart '.mb_strimwidth((string) $this->serviceName(), 0, 40, '…'),
            ChatAction::Mute => 'Mute 1 hour',
        };
    }
}
