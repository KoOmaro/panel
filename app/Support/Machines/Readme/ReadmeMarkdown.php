<?php

namespace App\Support\Machines\Readme;

use App\Support\Health\WorkspaceHealth;
use Carbon\CarbonImmutable;

/**
 * @phpstan-import-type Facts from ReadmeFacts
 * @phpstan-import-type Sections from ReadmeWords
 */
final class ReadmeMarkdown
{
    private const array HOW = [
        'system' => 'System', 'docker' => 'Docker', 'pm2' => 'PM2', 'supervisor' => 'Supervisor',
        'screen' => 'screen', 'cron' => 'cron', 'manual' => 'By hand', 'other' => 'Other',
    ];

    /**
     * @param  Facts  $facts
     * @param  Sections  $words
     */
    public static function render(array $facts, array $words, ?CarbonImmutable $at): string
    {
        $t = ReadmeFacts::t(...);
        $machine = $facts['machine'];
        $lines = ['# '.$machine['name'], ''];
        $lines[] = '_'.($at === null
            ? $t('Written by ServerOS from what’s running now.')
            : $t('Written by ServerOS from what’s running now, :date UTC.', ['date' => $at->utc()->format('j M Y H:i')])).'_';
        $lines[] = '';
        $lines[] = self::text($words['intro']);
        $lines[] = '';

        $lines[] = '## '.$t('How it’s wired');
        $lines[] = '';
        $wiring = $facts['wiring'];
        if ($wiring['entry'] !== null) {
            $lines[] = '- '.$t('Internet').' → '.self::chip($wiring['entry']);
            $routed = array_filter($wiring['apps'], fn (array $a) => $a['routed']);
            if ($routed !== []) {
                $lines[] = '- '.self::chip($wiring['entry']).' → '.implode(', ', array_map(self::chip(...), $routed));
            }
        }
        $apps = collect($wiring['apps'])->keyBy('id');
        foreach ($apps as $id => $app) {
            $deps = array_filter($wiring['deps'], fn (array $d) => in_array($id, $d['from'], true));
            if ($deps !== []) {
                $lines[] = '- '.self::chip($app).' → '.implode(', ', array_map(fn (array $d) => self::chip($d).($d['machine'] !== null
                    ? ' '.($d['private'] ? $t('on :machine, over the private network', ['machine' => $d['machine']['name']]) : $t('on :machine', ['machine' => $d['machine']['name']]))
                    : ''), $deps));
            }
        }
        $lines[] = '';
        $lines[] = self::text($words['wiring']);
        $lines[] = '';

        $lines[] = '## '.$t('What runs here');
        $lines[] = '';
        $lines[] = '| '.$t('Service').' | '.$t('What it does').' | '.$t('Runs as').' | '.$t('Ports').' |';
        $lines[] = '| --- | --- | --- | --- |';
        foreach ($facts['services'] as $service) {
            $how = str_starts_with($service['how'], 'site:') ? substr($service['how'], 5) : $t(self::HOW[$service['how']] ?? 'Other');
            $lines[] = '| '.self::cell($service['name']).' | '.self::cell(self::text($words['services'][$service['id']] ?? '')).' | '.$how.' | '
                .($service['ports'] === [] ? '' : implode(' ', array_map(fn (int $p) => '`:'.$p.'`', $service['ports']))).' |';
        }
        $lines[] = '';

        $lines[] = '## '.$t('Where data lives');
        $lines[] = '';
        if ($facts['data'] === []) {
            $lines[] = $t('ServerOS didn’t find any data on this machine.');
        } else {
            $lines[] = '| '.$t('What').' | '.$t('Where').' | '.$t('Size').' | '.$t('Backup').' |';
            $lines[] = '| --- | --- | --- | --- |';
            foreach ($facts['data'] as $row) {
                $lines[] = '| '.self::cell($row['what']).' | `'.str_replace('`', '', $row['where']).'` | '.($row['size'] === null ? '–' : WorkspaceHealth::bytes($row['size'])).' | '.self::cell($row['backup']).' |';
            }
        }
        $lines[] = '';

        $lines[] = '## '.$t('What’s risky');
        $lines[] = '';
        if ($facts['risks'] === []) {
            $lines[] = $t('Nothing that ServerOS can see.');
        }
        foreach ($facts['risks'] as $risk) {
            $lines[] = '- '.($risk['severity'] === 'urgent' ? '**'.$t('Urgent').':** ' : '').'**'.rtrim($risk['title'], '.').'.** '.$risk['detail'];
        }
        $lines[] = '';

        $lines[] = '## '.$t('Looks good');
        $lines[] = '';
        foreach ($facts['good'] as $good) {
            $lines[] = '- '.$good['text'];
        }
        if ($facts['good'] === []) {
            $lines[] = $t('Nothing to show yet.');
        }

        return implode("\n", $lines)."\n";
    }

    /** @param  array{name: string, port: int|null, note: string|null}  $chip */
    private static function chip(array $chip): string
    {
        return $chip['name'].($chip['port'] !== null ? ' `:'.$chip['port'].'`' : '').($chip['note'] === 'static' ? ' ('.ReadmeFacts::t('static files').')' : '');
    }

    private static function text(string $text): string
    {
        return (string) preg_replace('/!!(.+?)!!/u', '**$1**', $text);
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }
}
