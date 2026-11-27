<?php

namespace App\Jobs;

use App\Support\Integrations\Slack\SlackRequests;
use Illuminate\Foundation\Bus\Dispatchable;

/** A Slack button press or command, done once Slack has had its answer. */
final class HandleSlackRequest
{
    use Dispatchable;

    public const string INTERACTION = 'interaction';

    public const string COMMAND = 'command';

    /** @param  array<string, mixed>  $data */
    public function __construct(public readonly string $kind, public readonly array $data) {}

    public function handle(SlackRequests $requests): void
    {
        $this->kind === self::INTERACTION ? $requests->interaction($this->data) : $requests->command($this->data);
    }
}
