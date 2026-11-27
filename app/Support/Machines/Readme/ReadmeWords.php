<?php

namespace App\Support\Machines\Readme;

use App\Models\Workspace;
use App\Support\Ai\AiFeatures;
use App\Support\Ai\AiProviders;
use App\Support\Assistant\AssistantAllowance;
use RuntimeException;

/**
 * @phpstan-import-type Facts from ReadmeFacts
 * @phpstan-import-type Service from ReadmeFacts
 *
 * @phpstan-type Sections array{intro: string, wiring: string, services: array<string, string>}
 * @phpstan-type Written array{sections: Sections, source: string, model: string|null, reason: string|null}
 */
final class ReadmeWords
{
    public const int MAX_INTRO = 900;

    public const int MAX_WIRING = 500;

    public const int MAX_SERVICE = 220;

    private const array TECH_NAMES = ['next.js', 'node.js', 'nuxt.js', 'vue.js', 'express.js', 'nest.js', 'socket.io', 'asp.net'];

    public function __construct(private readonly AiProviders $ai) {}

    /**
     * @param  Facts  $facts
     * @return array<string, mixed>
     */
    public static function brief(array $facts): array
    {
        $machine = $facts['machine'];

        return [
            'machine' => array_filter([
                'name' => $machine['name'],
                'provider' => $machine['provider'],
                'plan' => $machine['plan'],
                'city' => $machine['city'],
                'country' => $machine['country'],
                'os' => $machine['os'],
                'environment' => $machine['environment'],
                'cloudflare_tunnel' => $machine['tunnel'] ?: null,
            ], fn ($value) => $value !== null),
            'sites' => $facts['sites'],
            'services' => array_map(fn (array $s) => array_filter([
                'id' => $s['id'],
                'name' => $s['name'],
                'kind' => $s['kind'],
                'runs_as' => $s['how'],
                'ports' => $s['ports'] ?: null,
                'status' => $s['status'] === 'running' ? null : $s['status'],
                'image' => $s['image'],
                'framework' => $s['framework'],
                'repo' => $s['repo'],
                'deploys_on_push_to' => $s['deploysOnPush'] ? $s['branch'] : null,
                'domains' => $s['domains'] ?: null,
                'project' => $s['project'],
                'serves_files_from' => $s['roots'] ?: null,
            ], fn ($value) => $value !== null), $facts['services']),
            'wiring' => [
                'takes_web_traffic' => $facts['wiring']['entry'] === null ? null : $facts['wiring']['entry']['name'],
                'apps' => array_map(fn (array $a) => array_filter([
                    'name' => $a['name'],
                    'reached_from_internet' => $a['routed'],
                    'static_files' => $a['note'] === 'static' ? true : null,
                ], fn ($value) => $value !== null), $facts['wiring']['apps']),
                'uses' => array_map(fn (array $d) => array_filter([
                    'name' => $d['name'],
                    'on_machine' => $d['machine']['name'] ?? null,
                    'over_private_network' => $d['private'] ?: null,
                    'used_by' => array_map(
                        fn (string $id) => collect($facts['wiring']['apps'])->firstWhere('id', $id)['name'] ?? $id,
                        $d['from'],
                    ),
                ], fn ($value) => $value !== null), $facts['wiring']['deps']),
            ],
            'open_to_internet' => $facts['open'],
            'data' => array_map(fn (array $row) => ['what' => $row['what'], 'where' => $row['where'], 'backup' => $row['backup']], $facts['data']),
            'needs_you' => array_map(fn (array $risk) => ['urgent' => $risk['severity'] === 'urgent', 'what' => $risk['title']], $facts['risks']),
            'looks_good' => array_column($facts['good'], 'text'),
        ];
    }

    /** @param  Facts  $facts */
    public static function hash(array $facts): string
    {
        $brief = self::brief($facts);
        $brief['data'] = array_map(fn (array $row) => [$row['what'], $row['where']], $brief['data']);
        $brief['needs_you'] = array_map(fn (array $risk) => [$risk['id'], $risk['severity']], $facts['risks']);
        $brief['looks_good'] = array_column($facts['good'], 'id');

        return hash('sha256', (string) json_encode($brief, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  Facts  $facts
     * @return Written
     */
    public function write(Workspace $workspace, array $facts): array
    {
        $ai = $this->ai->forFeature($workspace, AiFeatures::README);
        if (! $ai['own'] && $ai['key'] === '') {
            return self::fallback($facts, 'no_model');
        }
        if (AssistantAllowance::take($workspace) !== null) {
            return self::fallback($facts, 'quota');
        }

        try {
            [$answer, $model] = $this->ai->json($ai, self::instructions(), self::question($facts), 2000);
        } catch (RuntimeException) {
            return self::fallback($facts, 'error');
        }

        $sections = self::clean($answer, $facts);
        if ($sections === null) {
            return self::fallback($facts, 'rejected');
        }

        return ['sections' => $sections, 'source' => 'ai', 'model' => mb_substr($model, 0, 120), 'reason' => null];
    }

    /**
     * @param  Facts  $facts
     * @return Written
     */
    public static function fallback(array $facts, string $reason): array
    {
        return ['sections' => self::panel($facts), 'source' => 'panel', 'model' => null, 'reason' => $reason];
    }

    private static function instructions(): string
    {
        return <<<'TXT'
You write the readme of one server (a machine someone owns and just connected to ServerOS), for the person who runs it. They should read it and think "oh, that's my server". You're given the facts ServerOS found on it as JSON. Use only those facts.

Answer with one JSON object and nothing else:
{
  "intro": "one paragraph, 3 to 5 sentences: what the machine is (provider, plan, city, system), the sites it serves, what takes the web traffic and which apps it hands it to, what they keep their data in (and on which other machine), and, only when needs_you isn't empty, the one thing that needs them most.",
  "wiring": "one or two sentences about how requests flow: what takes them, which apps get them, what those apps use on this machine and what they reach on other machines over the private network.",
  "services": { "<service id>": "one short sentence saying what this service does here, in plain words" }
}

Rules:
- Never mention a service, site, port, path, number, version, repository or machine that isn't in the facts. Don't guess what an app does beyond its name, framework, project, domains and repo.
- Never invent a problem. Mention a risk only from needs_you, in your own words, and wrap that phrase in !!double exclamation marks!!, like "One thing needs you now: !!an openssl bug that's being exploited!!." Leave it out when needs_you is empty.
- Wrap site names and other machines' names in **double asterisks**, like **shop.acme.dev** or **db-1**. Nothing else gets asterisks.
- services has one entry for each service id in the facts, keyed by its exact id, each under 140 characters.
- Write like ServerOS: plain, casual, short sentences, no hype, no marketing words, no em dashes, no lists, no headings.
TXT;
    }

    /** @param  Facts  $facts */
    private static function question(array $facts): string
    {
        return "Facts about the machine (JSON):\n".json_encode(self::brief($facts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<mixed>  $answer
     * @param  Facts  $facts
     * @return Sections|null
     */
    public static function clean(array $answer, array $facts): ?array
    {
        $intro = $answer['intro'] ?? null;
        $wiring = $answer['wiring'] ?? null;
        $services = $answer['services'] ?? null;
        if (! is_string($intro) || ! is_string($wiring) || ! is_array($services)) {
            return null;
        }
        $intro = self::tidy($intro);
        $wiring = self::tidy($wiring);
        if ($intro === '' || mb_strlen($intro) > self::MAX_INTRO || mb_strlen($wiring) > self::MAX_WIRING) {
            return null;
        }

        $ids = array_column($facts['services'], 'id');
        $sentences = [];
        foreach ($services as $id => $sentence) {
            if (! is_string($id) || ! in_array($id, $ids, true) || ! is_string($sentence)) {
                continue;
            }
            $sentence = self::tidy($sentence);
            if ($sentence !== '' && mb_strlen($sentence) <= self::MAX_SERVICE) {
                $sentences[$id] = $sentence;
            }
        }

        $known = self::known($facts);
        foreach ([$intro, $wiring, ...array_values($sentences)] as $text) {
            if (! self::backed($text, $known, $facts['risks'] !== [])) {
                return null;
            }
        }
        foreach ($sentences as $sentence) {
            if (str_contains($sentence, '!!')) {
                return null;
            }
        }
        if (str_contains($wiring, '!!')) {
            return null;
        }

        return ['intro' => $intro, 'wiring' => $wiring, 'services' => $sentences];
    }

    private static function tidy(string $text): string
    {
        $text = (string) preg_replace('/\s*[\x{2014}\x{2013}]\s*/u', ', ', trim($text));
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return (string) preg_replace('/,\s*([.,])/u', '$1', $text);
    }

    /**
     * @param  Facts  $facts
     * @return array{text: string, numbers: list<string>, names: list<string>}
     */
    private static function known(array $facts): array
    {
        $text = strtolower((string) json_encode(self::brief($facts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        preg_match_all('/\d+(?:\.\d+)*/', $text, $numbers);

        $names = [$facts['machine']['name'], ...$facts['sites']];
        foreach ($facts['services'] as $service) {
            array_push($names, $service['name'], ...$service['domains']);
            if ($service['repo'] !== null) {
                $names[] = $service['repo'];
            }
            if ($service['project'] !== null) {
                $names[] = $service['project'];
            }
        }
        foreach ($facts['wiring']['deps'] as $dep) {
            $names[] = $dep['name'];
            if ($dep['machine'] !== null) {
                $names[] = $dep['machine']['name'];
            }
        }

        return [
            'text' => $text,
            'numbers' => array_values(array_unique($numbers[0])),
            'names' => array_values(array_unique(array_map('strtolower', $names))),
        ];
    }

    /** @param  array{text: string, numbers: list<string>, names: list<string>}  $known */
    private static function backed(string $text, array $known, bool $risky): bool
    {
        if (substr_count($text, '**') % 2 !== 0 || substr_count($text, '!!') % 2 !== 0) {
            return false;
        }
        if (! $risky && str_contains($text, '!!')) {
            return false;
        }
        preg_match_all('/\*\*(.+?)\*\*/u', $text, $bold);
        foreach ($bold[1] as $name) {
            if (! in_array(strtolower(trim($name)), $known['names'], true)) {
                return false;
            }
        }
        $plain = strtolower(str_replace(['**', '!!'], '', $text));

        preg_match_all('/(?<![a-z])\d+(?:\.\d+)*/', $plain, $numbers);
        foreach ($numbers[0] as $number) {
            if (! in_array($number, $known['numbers'], true)) {
                return false;
            }
        }
        preg_match_all('#(?<![\w.])/[a-z0-9._-]+(?:/[a-z0-9._-]+)*#', $plain, $paths);
        foreach ($paths[0] as $path) {
            if (! str_contains($known['text'], $path)) {
                return false;
            }
        }
        preg_match_all('/\b(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}\b/', $plain, $domains);
        foreach ($domains[0] as $domain) {
            if (! in_array($domain, self::TECH_NAMES, true) && ! str_contains($known['text'], $domain)) {
                return false;
            }
        }
        preg_match_all('#\b[a-z0-9-]+/[a-z0-9._-]+\b#', $plain, $repos);
        foreach ($repos[0] as $repo) {
            if (! str_contains($known['text'], $repo)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Facts  $facts
     * @return Sections
     */
    public static function panel(array $facts): array
    {
        $m = $facts['machine'];
        $t = ReadmeFacts::t(...);
        $what = trim(implode(' ', array_filter([$m['provider'], $m['plan']])));
        $sentences = [];
        $sentences[] = match (true) {
            $what !== '' && $m['city'] !== null && $m['os'] !== null => $t(':name is a :what in :city running :os.', ['name' => $m['name'], 'what' => $what, 'city' => $m['city'], 'os' => $m['os']]),
            $what !== '' && $m['os'] !== null => $t(':name is a :what running :os.', ['name' => $m['name'], 'what' => $what, 'os' => $m['os']]),
            $m['os'] !== null => $t(':name runs :os.', ['name' => $m['name'], 'os' => $m['os']]),
            default => $t('This is :name.', ['name' => $m['name']]),
        };
        $sites = array_map(fn (string $site) => "**{$site}**", array_slice($facts['sites'], 0, 4));
        if (count($facts['sites']) === 1) {
            $sentences[] = $t('It serves one site, :site.', ['site' => $sites[0]]);
        } elseif ($sites !== []) {
            $sentences[] = count($facts['sites']) > 4
                ? $t('It serves :count sites, like :sites.', ['count' => count($facts['sites']), 'sites' => ReadmeFacts::list($sites)])
                : $t('It serves :count sites, :sites.', ['count' => count($facts['sites']), 'sites' => ReadmeFacts::list($sites)]);
        }
        $entry = $facts['wiring']['entry'];
        $routed = array_values(array_filter($facts['wiring']['apps'], fn (array $a) => $a['routed']));
        if ($entry !== null) {
            $sentences[] = $routed === []
                ? $t(':entry takes the web traffic.', ['entry' => $entry['name']])
                : $t(':entry takes the web traffic and hands it to :apps.', ['entry' => $entry['name'], 'apps' => ReadmeFacts::list(array_column($routed, 'name'))]);
        }
        $remote = array_values(array_filter($facts['wiring']['deps'], fn (array $d) => $d['machine'] !== null));
        if ($remote !== []) {
            $sentences[] = $t('It reaches :what.', ['what' => ReadmeFacts::list(array_map(
                fn (array $d) => $t(':name on :machine', ['name' => $d['name'], 'machine' => '**'.$d['machine']['name'].'**']),
                array_slice($remote, 0, 3),
            ))]);
        }
        $urgent = collect($facts['risks'])->firstWhere('severity', 'urgent') ?? ($facts['risks'][0] ?? null);
        if ($urgent !== null) {
            $sentences[] = $t('One thing needs you now: :risk.', ['risk' => '!!'.rtrim(lcfirst($urgent['title']), '.').'!!']);
        }

        $wiring = [];
        if ($entry !== null) {
            $wiring[] = $routed === []
                ? $t('Requests come in through :entry.', ['entry' => $entry['name']])
                : $t('Requests come in through :entry, which passes them to :apps.', ['entry' => $entry['name'], 'apps' => ReadmeFacts::list(array_column($routed, 'name'))]);
        }
        $local = array_values(array_filter($facts['wiring']['deps'], fn (array $d) => $d['machine'] === null));
        if ($local !== []) {
            $wiring[] = $t('On this machine they use :deps.', ['deps' => ReadmeFacts::list(array_column($local, 'name'))]);
        }
        if ($remote !== []) {
            $private = collect($remote)->every(fn (array $d) => $d['private']);
            $list = ReadmeFacts::list(array_map(fn (array $d) => $t(':name on :machine', ['name' => $d['name'], 'machine' => $d['machine']['name']]), $remote));
            $wiring[] = $private
                ? $t('They reach :list over the private network.', ['list' => $list])
                : $t('They reach :list.', ['list' => $list]);
        }
        if ($facts['open'] !== null && $facts['open'] !== []) {
            $wiring[] = $t('Open to the internet: :ports.', ['ports' => ReadmeFacts::list(array_map('strval', $facts['open']))]);
        }
        if ($wiring === []) {
            $wiring[] = $t('Nothing on :name takes web traffic that ServerOS can see.', ['name' => $m['name']]);
        }

        $services = [];
        foreach ($facts['services'] as $service) {
            $services[$service['id']] = self::describe($service, $facts);
        }

        return ['intro' => implode(' ', $sentences), 'wiring' => implode(' ', $wiring), 'services' => $services];
    }

    /**
     * @param  Service  $service
     * @param  Facts  $facts
     */
    private static function describe(array $service, array $facts): string
    {
        $t = ReadmeFacts::t(...);
        $ports = array_map('strval', $service['ports']);
        $entry = $facts['wiring']['entry']['id'] ?? null;
        if ($service['id'] === $entry) {
            return $ports === []
                ? $t('Takes the web traffic and passes it to the right app.')
                : (count($ports) === 1
                    ? $t('Takes requests on port :ports and passes them to the right app.', ['ports' => $ports[0]])
                    : $t('Takes requests on ports :ports and passes them to the right app.', ['ports' => ReadmeFacts::list($ports)]));
        }
        if ($service['kind'] === 'site') {
            return $t(':site, plain files in :root.', ['site' => $service['name'], 'root' => $service['roots'][0] ?? '']);
        }
        $parts = [];
        if ($service['app'] !== null) {
            $parts[] = $service['domains'] !== []
                ? $t('Serves :domains.', ['domains' => ReadmeFacts::list($service['domains'])])
                : $t('An app ServerOS deployed.');
            if ($service['repo'] !== null) {
                $parts[] = $service['deploysOnPush']
                    ? $t('A push to :branch on :repo deploys a new version.', ['branch' => (string) $service['branch'], 'repo' => $service['repo']])
                    : $t('Deployed from :repo.', ['repo' => $service['repo']]);
            }

            return implode(' ', $parts);
        }

        return match ($service['kind']) {
            'database' => $service['project'] !== null ? $t('The :project database.', ['project' => $service['project']]) : $t('A database.'),
            'cache' => $service['project'] !== null ? $t('The :project cache.', ['project' => $service['project']]) : $t('A cache.'),
            'storage' => $service['project'] !== null ? $t('File storage for :project.', ['project' => $service['project']]) : $t('File storage.'),
            'proxy', 'web_server' => $t('A web server.'),
            'runtime' => $t('A language runtime.'),
            'game_server' => $t('A game server.'),
            default => $service['image'] !== null
                ? $t('A container from :image.', ['image' => $service['image']])
                : ($service['how'] === 'system' ? $t('A system service.') : $t('Runs here.')),
        };
    }
}
