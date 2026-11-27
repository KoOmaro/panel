<?php

namespace App\Support\Integrations\Teams;

use App\Models\DaemonJob;
use App\Support\Integrations\Chat\ChatAlert;

/**
 * Adaptive Cards (adaptivecards.io, schema 1.4) wrapped the way a Teams
 * workflow webhook takes them. Their only action opens the page in ServerOS:
 * buttons that do something need a Bot Framework bot, which this isn't.
 */
final class TeamsCards
{
    public const string VERSION = '1.4';

    /** @return array<string, mixed> */
    public static function alert(ChatAlert $alert): array
    {
        $facts = [['title' => 'Workspace', 'value' => $alert->workspace->name]];
        $machine = $alert->extra['machine']['name'] ?? null;
        if (is_string($machine)) {
            $facts[] = ['title' => 'Machine', 'value' => $machine];
        }
        if (($service = $alert->serviceName()) !== null) {
            $facts[] = ['title' => 'Service', 'value' => $service];
        }
        if ($alert->isDeployNotice() && is_string($id = $alert->extra['job_id'] ?? null) && ($job = DaemonJob::query()->with('user:id,name')->find($id)) instanceof DaemonJob) {
            $commit = (string) ($job->command['job']['commit'] ?? '');
            if ($commit !== '') {
                $facts[] = ['title' => 'Commit', 'value' => substr($commit, 0, 7)];
            }
            if ($job->trigger === 'push') {
                $facts[] = ['title' => 'Started by', 'value' => 'A push'];
            } elseif ($job->user !== null) {
                $facts[] = ['title' => 'Started by', 'value' => $job->user->name];
            }
        }
        $good = ! $alert->bad;

        return self::card(
            ($good ? '✅ ' : '🔴 ').$alert->subject,
            array_map('strval', $alert->lines),
            $facts,
            $good ? 'Good' : 'Attention',
            [[$alert->isDeployNotice() ? 'Open the deploy' : 'Open in ServerOS', $alert->url]],
        );
    }

    /** @return array<string, mixed> */
    public static function test(string $workspace, string $url): array
    {
        return self::card('Test from ServerOS', ["Alerts and deploys for {$workspace} will arrive in this channel."], [], 'Default', [['Open in ServerOS', $url]]);
    }

    /**
     * @param  list<string>  $lines
     * @param  list<array{title: string, value: string}>  $facts
     * @param  list<array{0: string, 1: string}>  $links
     * @return array<string, mixed>
     */
    public static function card(string $title, array $lines, array $facts, string $color, array $links): array
    {
        $body = [['type' => 'TextBlock', 'text' => self::plain($title), 'weight' => 'Bolder', 'size' => 'Medium', 'color' => $color, 'wrap' => true]];
        foreach ($lines as $line) {
            $body[] = ['type' => 'TextBlock', 'text' => self::plain($line), 'wrap' => true, 'spacing' => 'Small'];
        }
        if ($facts !== []) {
            $body[] = ['type' => 'FactSet', 'facts' => array_map(fn (array $f) => ['title' => self::plain($f['title']), 'value' => self::plain($f['value'])], $facts)];
        }

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => self::VERSION,
                    'msteams' => ['width' => 'Full'],
                    'body' => $body,
                    'actions' => array_values(array_map(fn (array $link) => ['type' => 'Action.OpenUrl', 'title' => $link[0], 'url' => $link[1]], array_filter($links, fn (array $l) => str_starts_with($l[1], 'https://') || str_starts_with($l[1], 'http://')))),
                ],
            ]],
        ];
    }

    /** Text as it is: Teams reads a little Markdown in a TextBlock, so its marks are escaped. */
    private static function plain(string $text): string
    {
        return mb_substr((string) preg_replace('/([\\\\*_~`#\[\]])/', '\\\\$1', $text), 0, 2000);
    }
}
