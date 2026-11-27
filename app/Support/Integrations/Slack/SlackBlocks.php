<?php

namespace App\Support\Integrations\Slack;

use App\Models\ChatPost;
use App\Models\DaemonJob;
use App\Models\SlackMessage;
use App\Support\Deploys\AppVersions;
use App\Support\Integrations\Chat\ChatAction;
use App\Support\Integrations\Chat\ChatAlert;
use Carbon\CarbonImmutable;

/**
 * Block Kit messages (docs.slack.dev/block-kit): alerts and deploy notices
 * are drawn from their SlackMessage every time, so a fix or a button press
 * redraws the whole message the same way. Buttons carry the ChatPost's id.
 */
final class SlackBlocks
{
    public const string PREFIX = 'serveros.';

    public const string ROLLBACK = 'rollback';

    public const string DEPLOY = 'deploy';

    public const string CANCEL = 'cancel';

    public const string OPEN = 'open';

    public const string LINK = 'link';

    /** @return array<string, mixed> what an alert's message keeps, to draw it */
    public static function alertPayload(ChatAlert $alert): array
    {
        return [
            'event' => $alert->event,
            'subject' => $alert->subject,
            'lines' => array_map('strval', $alert->lines),
            'url' => $alert->url,
            'open' => $alert->actionLabel,
            'bad' => $alert->bad,
            'actions' => array_map(fn (ChatAction $action) => ['action' => $action->value, 'label' => $alert->label($action)], $alert->actions()),
        ];
    }

    /** @return array<string, mixed> what a deploy notice keeps: the deploy, and the version before it to roll back to */
    public static function deployPayload(ChatAlert $alert): array
    {
        $payload = [
            'event' => $alert->event,
            'subject' => $alert->subject,
            'lines' => array_map('strval', $alert->lines),
            'url' => $alert->url,
            'open' => 'Open the deploy',
            'bad' => $alert->bad,
            'actions' => [],
        ];
        $id = $alert->extra['job_id'] ?? null;
        $job = is_string($id) ? DaemonJob::query()->with(['machine', 'user:id,name'])->find($id) : null;
        if (! $job instanceof DaemonJob || ! is_string($job->service)) {
            return $payload;
        }
        $commit = strtolower((string) ($job->command['job']['commit'] ?? ''));
        $payload += [
            'machine' => $job->machine_id,
            'machineName' => $job->machine->name,
            'app' => $job->service,
            'commit' => $commit,
            'by' => $job->user?->name,
            'trigger' => $job->trigger,
        ];
        if ($job->state === 'succeeded' && $commit !== '') {
            $before = collect(AppVersions::for($job->machine, $job->service))->first(fn (array $v) => $v['commit'] !== $commit);
            if ($before !== null) {
                $payload['previous'] = [
                    'commit' => $before['commit'],
                    'at' => $before['job']->created_at?->toIso8601String(),
                ];
            }
        }

        return $payload;
    }

    /**
     * The message as it should look now.
     *
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    public static function draw(SlackMessage $message): array
    {
        $p = $message->payload;
        $post = $message->chat_post_id;
        $resolved = is_array($p['resolved'] ?? null) ? $p['resolved'] : null;
        $deploy = $message->kind === SlackMessage::DEPLOY;
        $subject = (string) ($p['subject'] ?? '');

        $mark = match (true) {
            $resolved !== null => ':large_green_circle:',
            $deploy => ($p['bad'] ?? false) ? ':red_circle:' : ':large_green_circle:',
            (bool) ($p['bad'] ?? true) => ':red_circle:',
            default => ':large_green_circle:',
        };
        $lines = array_map('strval', (array) ($p['lines'] ?? []));
        $text = $mark.' *'.self::escape($subject).($resolved !== null ? ' (fixed)' : '').'*';
        if ($lines !== []) {
            $text .= "\n".implode("\n", array_map(fn (string $line) => self::escape($line), $lines));
        }
        $blocks = [self::section($text)];

        $context = [];
        if ($deploy && is_string($p['commit'] ?? null) && $p['commit'] !== '') {
            $who = match (true) {
                ($p['trigger'] ?? null) === 'push' => ' · from a push',
                is_string($p['by'] ?? null) => ' · by '.self::escape($p['by']),
                default => '',
            };
            $context[] = '`'.substr($p['commit'], 0, 7).'`'.$who;
        }
        if ($resolved !== null) {
            $context[] = self::escape(trim((string) ($resolved['subject'] ?? 'Fixed').'. '.implode(' ', array_map('strval', (array) ($resolved['lines'] ?? []))))).' '.self::when((string) ($resolved['at'] ?? ''));
        }
        foreach ($message->notes ?? [] as $note) {
            $context[] = self::escape((string) $note['text']).' '.self::when((string) $note['at']);
        }
        foreach ($context as $line) {
            $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => mb_substr($line, 0, 3000)]]];
        }

        $buttons = [];
        if ($resolved === null) {
            foreach ((array) ($p['actions'] ?? []) as $action) {
                if (is_array($action) && ChatAction::tryFrom((string) ($action['action'] ?? '')) !== null) {
                    $buttons[] = self::button((string) $action['label'], (string) $action['action'], ['post' => $post]);
                }
            }
        }
        $previous = is_array($p['previous'] ?? null) ? $p['previous'] : null;
        if ($deploy && $previous !== null && ! isset($p['rolledBack']) && is_string($p['app'] ?? null)) {
            $short = substr((string) $previous['commit'], 0, 7);
            $buttons[] = self::button('Roll back to '.$short, self::ROLLBACK, ['post' => $post, 'commit' => (string) $previous['commit']], confirm: [
                'title' => 'Roll back '.mb_strimwidth((string) $p['app'], 0, 60, '…').'?',
                'text' => self::escape((string) $p['app'].' on '.($p['machineName'] ?? 'its machine').' goes back to ').'`'.$short.'`'.(is_string($previous['at'] ?? null) ? ', deployed '.CarbonImmutable::parse($previous['at'])->diffForHumans() : '').'. Its data stays as it is.',
                'confirm' => 'Roll back',
            ]);
        }
        if (is_string($p['url'] ?? null) && $p['url'] !== '') {
            $buttons[] = self::link((string) ($p['open'] ?? 'Open in ServerOS'), (string) $p['url']);
        }
        if ($buttons !== []) {
            $blocks[] = ['type' => 'actions', 'block_id' => 'serveros-'.$post, 'elements' => array_slice($buttons, 0, 5)];
        }

        return ['text' => mb_substr(($resolved !== null ? 'Fixed: ' : '').$subject, 0, 300), 'blocks' => $blocks];
    }

    /** @return array<string, mixed> */
    public static function section(string $mrkdwn): array
    {
        return ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr($mrkdwn, 0, 3000)]];
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array{title: string, text: string, confirm: string}|null  $confirm
     * @return array<string, mixed>
     */
    public static function button(string $label, string $action, array $value, ?string $style = null, ?array $confirm = null): array
    {
        return array_filter([
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => mb_strimwidth($label, 0, 75, '…')],
            'action_id' => self::PREFIX.$action,
            'value' => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            'style' => $style,
            'confirm' => $confirm === null ? null : [
                'title' => ['type' => 'plain_text', 'text' => mb_strimwidth($confirm['title'], 0, 100, '…')],
                'text' => ['type' => 'mrkdwn', 'text' => mb_strimwidth($confirm['text'], 0, 300, '…')],
                'confirm' => ['type' => 'plain_text', 'text' => mb_strimwidth($confirm['confirm'], 0, 30, '…')],
                'deny' => ['type' => 'plain_text', 'text' => 'Cancel'],
            ],
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> a button that opens a page; Slack still sends its press, which is only acknowledged */
    public static function link(string $label, string $url, string $action = self::OPEN): array
    {
        return [
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => mb_strimwidth($label, 0, 75, '…')],
            'action_id' => self::PREFIX.$action,
            'url' => mb_substr($url, 0, 3000),
        ];
    }

    /**
     * A reply only the person who pressed or typed sees.
     *
     * @param  list<array<string, mixed>>  $buttons
     * @return array<string, mixed>
     */
    public static function ephemeral(string $mrkdwn, array $buttons = [], bool $replace = false): array
    {
        $blocks = [self::section($mrkdwn)];
        if ($buttons !== []) {
            $blocks[] = ['type' => 'actions', 'elements' => $buttons];
        }

        return [
            'response_type' => 'ephemeral',
            'replace_original' => $replace,
            'text' => mb_substr(strip_tags($mrkdwn), 0, 300),
            'blocks' => $blocks,
        ];
    }

    /** Text in mrkdwn without Slack reading it as a link, mention or tag. */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /** The time in each reader's own zone (Slack's date formatting), with a fallback. */
    public static function when(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        $at = CarbonImmutable::parse($iso);

        return '· <!date^'.$at->getTimestamp().'^{time}|'.$at->format('H:i').' UTC>';
    }

    public static function permalink(?string $teamUrl, string $channel, string $ts): ?string
    {
        if ($teamUrl === null || ! str_starts_with($teamUrl, 'https://') || $ts === '') {
            return null;
        }

        return rtrim($teamUrl, '/').'/archives/'.rawurlencode($channel).'/p'.str_replace('.', '', $ts);
    }

    /** @return array{post: int|null, commit: string|null, i: int|null, m: int|null, app: string|null} what a button's value says */
    public static function value(mixed $value): array
    {
        $data = is_string($value) ? json_decode($value, true) : null;
        $data = is_array($data) ? $data : [];

        return [
            'post' => is_int($data['post'] ?? null) ? $data['post'] : null,
            'commit' => is_string($data['commit'] ?? null) && preg_match('/^[0-9a-f]{7,64}$/', $data['commit']) === 1 ? $data['commit'] : null,
            'i' => is_int($data['i'] ?? null) ? $data['i'] : null,
            'm' => is_int($data['m'] ?? null) ? $data['m'] : null,
            'app' => is_string($data['app'] ?? null) ? $data['app'] : null,
        ];
    }

    public static function isPost(ChatPost $post): bool
    {
        return $post->chat === SlackChat::CHAT;
    }
}
