<?php

namespace App\Support\Integrations\OVHcloud;

use App\Models\Integration;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;
use App\Support\Integrations\Servers\ActionState;
use App\Support\Integrations\Servers\CloudInit;
use App\Support\Integrations\Servers\LimitsServers;
use App\Support\Integrations\Servers\ManagesServers;
use App\Support\Integrations\Servers\NewServer;
use App\Support\Integrations\Servers\Server;
use App\Support\Integrations\Servers\ServerImage;
use App\Support\Integrations\Servers\ServerLocation;
use App\Support\Integrations\Servers\ServerPlans;
use App\Support\Integrations\Servers\ServerRules;
use App\Support\Integrations\Servers\ServerType;
use App\Support\Integrations\Servers\Snapshot;
use App\Support\Integrations\Servers\SshKey;
use App\Support\Integrations\Servers\StartedServer;
use Illuminate\Support\Facades\Cache;

/**
 * One OVHcloud Public Cloud project. Its instances are made, resized,
 * restarted and snapshotted; the account's dedicated servers are listed and
 * linked to machines by address, but only read (they're another API).
 * OVHcloud has no action objects for instances, so changes are followed by
 * watching the instance, and flavors and images have an id per region, so
 * ServerOS names them by their name and looks the id up in the region.
 */
final class OVHcloudProvider extends Provider implements LimitsServers, ManagesServers
{
    /** The rights the keys need, and nothing more. */
    public const array RIGHTS = [
        'GET' => ['/me', '/cloud/project', '/cloud/project/*', '/dedicated/server', '/dedicated/server/*'],
        'POST' => ['/cloud/project/*'],
        'DELETE' => ['/cloud/project/*'],
    ];

    public const string DEDICATED = 'dedicated:';

    private const array SUBSIDIARIES = ['ovh-eu' => 'FR', 'ovh-ca' => 'CA', 'ovh-us' => 'US'];

    private const array CURRENCIES = ['ovh-eu' => 'EUR', 'ovh-ca' => 'CAD', 'ovh-us' => 'USD'];

    private const int MAX_PROJECTS = 25;

    private const int MAX_DEDICATED = 50;

    private const int HOURS_A_MONTH = 730;

    private const string SYSTEM = '/^(Ubuntu|Debian) (\d+(?:\.\d+)?)$/';

    private const string GPU = '/^(t1|t2|a10|a100|h100|h200|l4|l40s|rtx\d*)-/';

    private const array PLACES = [
        'GRA' => 'Gravelines', 'RBX' => 'Roubaix', 'SBG' => 'Strasbourg', 'PAR' => 'Paris', 'MRS' => 'Marseille',
        'BHS' => 'Beauharnois', 'TOR' => 'Toronto', 'DE' => 'Frankfurt', 'FRA' => 'Frankfurt', 'LIM' => 'Limburg',
        'UK' => 'London', 'ERI' => 'London', 'LON' => 'London', 'WAW' => 'Warsaw', 'MIL' => 'Milan', 'MAD' => 'Madrid',
        'BRU' => 'Brussels', 'AMS' => 'Amsterdam', 'ZRH' => 'Zurich', 'VIE' => 'Vienna', 'PRG' => 'Prague', 'LIS' => 'Lisbon',
        'DUB' => 'Dublin', 'CPH' => 'Copenhagen', 'HEL' => 'Helsinki', 'STO' => 'Stockholm', 'OSL' => 'Oslo', 'MAN' => 'Manchester',
        'SGP' => 'Singapore', 'SYD' => 'Sydney', 'YNM' => 'Mumbai', 'MUM' => 'Mumbai',
        'VIN' => 'Vint Hill', 'HIL' => 'Hillsboro', 'US-EAST-VA' => 'Vint Hill', 'US-WEST-OR' => 'Hillsboro',
        'NYC' => 'New York', 'ATL' => 'Atlanta', 'LAX' => 'Los Angeles', 'CHI' => 'Chicago', 'DAL' => 'Dallas', 'MIA' => 'Miami',
    ];

    private const array COUNTRIES = [
        'gra' => 'FR', 'rbx' => 'FR', 'sbg' => 'FR', 'par' => 'FR', 'bhs' => 'CA', 'tor' => 'CA', 'lim' => 'DE', 'fra' => 'DE',
        'eri' => 'GB', 'lon' => 'GB', 'waw' => 'PL', 'sgp' => 'SG', 'syd' => 'AU', 'ynm' => 'IN', 'vin' => 'US', 'hil' => 'US',
    ];

    public function key(): string
    {
        return 'ovhcloud';
    }

    public function name(): string
    {
        return 'OVHcloud';
    }

    public function fields(): array
    {
        $key = fn (string $key, string $label, string $placeholder) => new ConnectField(
            key: $key,
            label: $label,
            placeholder: $placeholder,
            rules: ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
        );

        return [
            new ConnectField(
                key: 'endpoint',
                label: self::t('OVHcloud account'),
                secret: false,
                help: self::t('Make keys opens OVHcloud’s token page with the rights ServerOS needs filled in. Sign in, set Validity to Unlimited and create the keys.'),
                link: self::tokenLink('ovh-eu'),
                linkLabel: self::t('Make keys'),
                rules: ['required', 'string', 'in:'.implode(',', array_keys(OVHcloudClient::ENDPOINTS))],
                value: 'ovh-eu',
                options: [
                    ['value' => 'ovh-eu', 'label' => self::t('Europe'), 'link' => self::tokenLink('ovh-eu')],
                    ['value' => 'ovh-ca', 'label' => self::t('Canada and Asia-Pacific'), 'link' => self::tokenLink('ovh-ca')],
                    ['value' => 'ovh-us', 'label' => self::t('United States'), 'link' => self::tokenLink('ovh-us')],
                ],
            ),
            $key('application_key', self::t('Application key'), self::t('Paste the application key')),
            $key('application_secret', self::t('Application secret'), self::t('Paste the application secret')),
            $key('consumer_key', self::t('Consumer key'), self::t('Paste the consumer key')),
            new ConnectField(
                key: 'project',
                label: self::t('Public Cloud project ID'),
                secret: false,
                help: self::t('Leave it empty when the account has one Public Cloud project. With several, copy the ID shown under the project’s name in the Control Panel.'),
                placeholder: self::t('Optional'),
                rules: ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            ),
        ];
    }

    public static function tokenLink(string $endpoint): string
    {
        $rights = [];
        foreach (self::RIGHTS as $method => $paths) {
            foreach ($paths as $path) {
                $rights[] = $method.'='.$path;
            }
        }

        return substr(OVHcloudClient::url($endpoint), 0, -strlen('/1.0')).'/createToken/?'.implode('&', $rights);
    }

    public function capabilities(): array
    {
        return [Capability::Servers, Capability::CreateServers, Capability::Resize, Capability::Power, Capability::Snapshots, Capability::DeleteServers];
    }

    public function capabilitiesOf(Server $server): array
    {
        return str_starts_with($server->id, self::DEDICATED) ? [Capability::Servers] : $this->capabilities();
    }

    public function describe(Capability $capability): string
    {
        return match ($capability) {
            Capability::Servers => self::t('Matches the project’s instances, and the account’s dedicated servers, to your machines by IP address, and lists the ones that aren’t in ServerOS yet. Dedicated servers are only listed.'),
            Capability::Resize => self::t('Moves a linked instance to another model with a disk at least as big, and restarts it. A flex model moves to any other flex model.'),
            Capability::Power => self::t('Restarts a linked instance gently, or hard reboots it, even when its daemon is offline.'),
            default => parent::describe($capability),
        };
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $client = self::clientFor($credentials);
        $me = $client->get('me');
        $projects = $this->projects($client);
        $wanted = trim($credentials['project'] ?? '');

        if ($wanted !== '') {
            $project = collect($projects)->firstWhere('id', $wanted);
            if ($project === null || in_array($project['status'], ['deleted', 'deleting'], true)) {
                throw new IntegrationFailed(self::t('These keys can’t see a Public Cloud project with that ID. Check it, or leave it empty when the account has one project.'), IntegrationFailed::NOT_FOUND, null, 'project');
            }
        } else {
            $usable = array_values(array_filter($projects, fn (array $p) => in_array($p['status'], ['ok', 'creating', 'suspended'], true)));
            if ($usable === []) {
                throw new IntegrationFailed(self::t('This OVHcloud account has no Public Cloud project yet. Make one in the Control Panel, then connect again.'), IntegrationFailed::NOT_FOUND, null, 'project');
            }
            if (count($usable) > 1) {
                throw new IntegrationFailed(self::t('This account has :count Public Cloud projects: :projects. Paste the ID of the one to connect.', [
                    'count' => count($usable),
                    'projects' => implode(', ', array_map(fn (array $p) => ($p['name'] === '' ? $p['id'] : $p['name']).' ('.$p['id'].')', $usable)),
                ]), IntegrationFailed::INVALID, null, 'project');
            }
            $project = $usable[0];
        }
        if ($project['status'] === 'suspended') {
            throw new IntegrationFailed(self::t('OVHcloud has suspended this Public Cloud project. Sort it out in the Control Panel, then connect again.'), IntegrationFailed::PERMISSION, null, 'project');
        }

        $instances = count($client->get('cloud/project/'.rawurlencode($project['id']).'/instance'));
        $dedicated = count($this->dedicatedNames($client));
        $endpoint = self::endpointOf($credentials);
        $currency = is_array($me['currency'] ?? null) ? ($me['currency']['code'] ?? null) : null;

        return new ConnectedAccount($project['name'] === '' ? 'OVHcloud' : $project['name'], [
            'account' => is_string($me['nichandle'] ?? null) ? $me['nichandle'] : null,
            'project' => $project['id'],
            'projectName' => $project['name'] === '' ? null : $project['name'],
            'endpoint' => $endpoint,
            'subsidiary' => is_string($me['ovhSubsidiary'] ?? null) ? $me['ovhSubsidiary'] : self::SUBSIDIARIES[$endpoint],
            'currency' => is_string($currency) ? $currency : self::CURRENCIES[$endpoint],
            'servers' => $instances + $dedicated,
            'dedicated' => $dedicated,
        ], $wanted === '' ? ['project' => $project['id']] : []);
    }

    public function summary(Integration $integration): string
    {
        $count = (int) ($integration->meta['servers'] ?? 0);

        return $count === 1 ? self::t('1 server') : self::t(':count servers', ['count' => $count]);
    }

    public function facts(Integration $integration): array
    {
        $meta = $integration->meta ?? [];
        $regions = ['ovh-eu' => self::t('Europe'), 'ovh-ca' => self::t('Canada and Asia-Pacific'), 'ovh-us' => self::t('United States')];
        $project = $this->projectId($integration);
        $dedicated = (int) ($meta['dedicated'] ?? 0);

        return array_values(array_filter([
            ['label' => self::t('Project'), 'value' => is_string($meta['projectName'] ?? null) ? $meta['projectName'] : $project],
            is_string($meta['account'] ?? null) ? ['label' => self::t('OVHcloud account'), 'value' => $meta['account']] : null,
            ['label' => self::t('Region'), 'value' => $regions[self::endpointOf($integration->credentials)]],
            ['label' => self::t('Servers'), 'value' => (string) (int) ($meta['servers'] ?? 0)],
            $dedicated > 0 ? ['label' => self::t('Dedicated servers, listed only'), 'value' => (string) $dedicated] : null,
        ]));
    }

    public function usage(Integration $integration): array
    {
        $rows = $integration->servers()->where('server_id', 'like', self::DEDICATED.'%')->with('machine:id,name')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $linked = $rows->pluck('machine.name')->filter()->sort()->values();
        $out = [(string) trans_choice(':count dedicated server is listed here. ServerOS only reads it.|:count dedicated servers are listed here. ServerOS only reads them.', $rows->count(), ['count' => $rows->count()])];
        if ($linked->isNotEmpty()) {
            $out[] = (string) trans_choice(':machines is on a dedicated server, so it has no server actions here.|:machines are on dedicated servers, so they have no server actions here.', $linked->count(), ['machines' => $linked->implode(', ')]);
        }

        return $out;
    }

    public function servers(Integration $integration): array
    {
        $client = $this->client($integration);
        $path = $this->path($integration);
        $flavors = [];
        foreach ($this->flavors($integration) as $flavor) {
            $flavors[$flavor['id']] = $flavor;
        }
        $regions = $this->regions($integration);

        $servers = [];
        foreach ($client->get($path.'/instance') as $instance) {
            if (! is_array($instance) || ! isset($instance['id'])) {
                continue;
            }
            $flavor = is_array($instance['flavor'] ?? null) ? self::flavorRow($instance['flavor']) : ($flavors[(string) ($instance['flavorId'] ?? '')] ?? null);
            if ($flavor === null) {
                $detail = $client->get($path.'/instance/'.rawurlencode((string) $instance['id']));
                $flavor = is_array($detail['flavor'] ?? null) ? self::flavorRow($detail['flavor']) : null;
            }
            $servers[] = $this->toServer($instance, $flavor, $regions);
        }

        foreach (array_slice($this->dedicatedNames($client), 0, self::MAX_DEDICATED) as $name) {
            $server = $this->dedicatedServer($integration, $client, $name);
            if ($server !== null) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    public function server(Integration $integration, string $id): ?Server
    {
        $client = $this->client($integration);
        if (str_starts_with($id, self::DEDICATED)) {
            return $this->dedicatedServer($integration, $client, substr($id, strlen(self::DEDICATED)));
        }

        try {
            $instance = $client->get($this->path($integration).'/instance/'.rawurlencode($id));
        } catch (IntegrationFailed $e) {
            if ($e->reason === IntegrationFailed::NOT_FOUND) {
                return null;
            }
            throw $e;
        }
        if (! isset($instance['id'])) {
            return null;
        }
        $flavor = is_array($instance['flavor'] ?? null) ? self::flavorRow($instance['flavor']) : null;
        if ($flavor === null) {
            foreach ($this->flavors($integration) as $row) {
                if ($row['id'] === ($instance['flavorId'] ?? null)) {
                    $flavor = $row;
                }
            }
        }

        return $this->toServer($instance, $flavor, $this->regions($integration));
    }

    public function plans(Integration $integration): ServerPlans
    {
        return ServerPlans::fromArray(Cache::remember("integrations:{$integration->id}:plans", now()->addMinutes(10), function () use ($integration): array {
            $client = $this->client($integration);
            $path = $this->path($integration);
            $regions = [];
            foreach ($this->regionRows($integration) as $row) {
                if ($row['up']) {
                    $regions[$row['name']] = new ServerLocation($row['name'], $row['city'], $row['country'], $row['zone']);
                }
            }
            $catalogue = $this->catalogue($integration);

            $byName = [];
            foreach ($this->flavors($integration) as $flavor) {
                if ($flavor['available'] && isset($regions[$flavor['region']]) && ! self::gpu($flavor)) {
                    $byName[$flavor['name']][] = $flavor;
                }
            }
            $types = [];
            foreach ($byName as $name => $rows) {
                $prices = [];
                foreach ($rows as $row) {
                    $price = self::priceOf($row, $catalogue['prices']);
                    if ($price !== null) {
                        $prices[$row['region']] = $price;
                    }
                }
                $locations = array_values(array_unique(array_map(fn (array $row) => $row['region'], $rows)));
                sort($locations);
                $types[] = new ServerType(
                    name: (string) $name,
                    label: (string) $name,
                    cores: $rows[0]['vcpus'],
                    memoryGb: $rows[0]['ram'],
                    diskGb: $rows[0]['disk'],
                    cpu: preg_match('/^(d2|s1|vps)-/', (string) $name) === 1 ? 'shared' : 'dedicated',
                    architecture: 'x86',
                    storage: 'local',
                    locations: $locations,
                    prices: $prices,
                );
            }
            usort($types, fn (ServerType $a, ServerType $b) => [$a->cpu !== 'shared', str_ends_with($a->name, '-flex'), $a->cores, $a->memoryGb, $a->diskGb, $a->name]
                <=> [$b->cpu !== 'shared', str_ends_with($b->name, '-flex'), $b->cores, $b->memoryGb, $b->diskGb, $b->name]);

            $offered = [];
            foreach ($types as $type) {
                foreach ($type->locations as $location) {
                    $offered[$location] = true;
                }
            }
            $locations = array_values(array_filter($regions, fn (ServerLocation $location) => isset($offered[$location->name])));

            $keys = [];
            foreach ($client->get($path.'/sshkey') as $key) {
                if (is_array($key) && isset($key['id'])) {
                    $keys[] = new SshKey((string) $key['id'], (string) ($key['name'] ?? $key['id']));
                }
            }

            $currency = $integration->meta['currency'] ?? $catalogue['currency'] ?? self::CURRENCIES[self::endpointOf($integration->credentials)];

            return (new ServerPlans($locations, $types, $this->images($client, $path), $keys, is_string($currency) ? $currency : 'EUR'))->toArray();
        }));
    }

    public function create(Integration $integration, NewServer $server, string $userData): StartedServer
    {
        $client = $this->client($integration);
        $path = $this->path($integration);
        $flavor = $this->flavorIn($client, $path, $server->location, $server->type)
            ?? throw new IntegrationFailed(self::t('OVHcloud can’t make that model in this region right now. Pick another.'));
        $image = null;
        foreach ($client->get($path.'/image', ['osType' => 'linux', 'region' => $server->location]) as $row) {
            if (is_array($row) && ($row['name'] ?? null) === $server->image && ($row['flavorType'] ?? null) === null && ($row['status'] ?? 'active') === 'active') {
                $image = (string) $row['id'];
            }
        }
        if ($image === null) {
            throw new IntegrationFailed(self::t('OVHcloud doesn’t offer that system in this region. Pick another.'));
        }

        $extra = [];
        if (count($server->keys) > 1) {
            foreach ($client->get($path.'/sshkey') as $key) {
                if (is_array($key) && in_array((string) ($key['id'] ?? ''), array_slice($server->keys, 1), true) && is_string($key['publicKey'] ?? null)) {
                    $extra[] = $key['publicKey'];
                }
            }
        }

        $instance = $client->post($path.'/instance', [
            'name' => $server->name,
            'region' => $server->location,
            'flavorId' => $flavor['id'],
            'imageId' => $image,
            'monthlyBilling' => false,
            'userData' => CloudInit::withKeys($userData, $extra),
            ...($server->keys === [] ? [] : ['sshKeyId' => $server->keys[0]]),
        ]);
        if (! isset($instance['id'])) {
            throw new IntegrationFailed(self::t('OVHcloud didn’t say which instance it made. Check the Control Panel before trying again.'));
        }

        return new StartedServer($this->toServer($instance, $flavor, $this->regions($integration)), null);
    }

    // OVHcloud moves a classic model only to one with a disk at least as big, and a flex model only to another flex model.
    public function resizeTypes(Integration $integration, Server $server): array
    {
        $flavors = array_values(array_filter(array_map(
            fn (mixed $row) => is_array($row) ? self::flavorRow($row) : null,
            $this->client($integration)->get($this->path($integration).'/flavor', ['region' => $server->location]),
        ), fn (?array $row) => $row !== null && $row['linux']));
        $current = collect($flavors)->firstWhere('name', $server->type);
        if ($current !== null && ! $current['resize']) {
            return [];
        }
        $flex = str_ends_with($server->type, '-flex');
        $allowed = [];
        foreach ($flavors as $row) {
            if ($row['name'] !== $server->type && $row['available'] && ! self::gpu($row)
                && ($flex ? str_ends_with($row['name'], '-flex') : ! str_ends_with($row['name'], '-flex') && $row['disk'] >= $server->diskGb)) {
                $allowed[] = $row['name'];
            }
        }

        return array_values(array_filter($this->plans($integration)->types, fn (ServerType $type) => in_array($type->name, $allowed, true)
            && in_array($server->location, $type->locations, true)));
    }

    public function resize(Integration $integration, string $id, string $type, bool $growDisk): ?string
    {
        $client = $this->client($integration);
        $path = $this->path($integration);
        $region = (string) ($client->get($path.'/instance/'.rawurlencode($id))['region'] ?? '');
        $flavor = $this->flavorIn($client, $path, $region, $type)
            ?? throw new IntegrationFailed(self::t('OVHcloud can’t move it to that model in this region right now. Pick another.'));
        $client->post($path.'/instance/'.rawurlencode($id).'/resize', ['flavorId' => $flavor['id']]);

        return null;
    }

    public function rules(): ServerRules
    {
        return new ServerRules(
            resizeNeedsPowerOff: false,
            resizeDisk: ServerRules::DISK_ALWAYS,
            resizeDown: true,
            gracefulRestart: true,
            snapshotsPerServer: true,
        );
    }

    public function shutdown(Integration $integration, string $id): ?string
    {
        return $this->powerOff($integration, $id);
    }

    public function powerOff(Integration $integration, string $id): ?string
    {
        $this->client($integration)->post($this->instancePath($integration, $id).'/stop');

        return null;
    }

    public function powerOn(Integration $integration, string $id): ?string
    {
        $this->client($integration)->post($this->instancePath($integration, $id).'/start');

        return null;
    }

    public function reboot(Integration $integration, string $id): ?string
    {
        $this->client($integration)->post($this->instancePath($integration, $id).'/reboot', ['type' => 'soft']);

        return null;
    }

    public function powerCycle(Integration $integration, string $id): ?string
    {
        $this->client($integration)->post($this->instancePath($integration, $id).'/reboot', ['type' => 'hard']);

        return null;
    }

    public function action(Integration $integration, string $actionId): ActionState
    {
        return new ActionState(ActionState::DONE);
    }

    public function snapshots(Integration $integration, string $serverId): array
    {
        $snapshots = [];
        foreach ($this->client($integration)->get($this->path($integration).'/snapshot', ['instanceId' => $serverId]) as $image) {
            if (is_array($image) && isset($image['id'])) {
                $snapshots[] = $this->toSnapshot($image);
            }
        }
        usort($snapshots, fn (Snapshot $a, Snapshot $b) => strcmp((string) $b->createdAt, (string) $a->createdAt));

        return $snapshots;
    }

    public function snapshot(Integration $integration, string $serverId, string $name): Snapshot
    {
        $body = $this->client($integration)->post($this->instancePath($integration, $serverId).'/snapshot', ['snapshotName' => $name]);
        if (is_string($body['snapshotId'] ?? null) && $body['snapshotId'] !== '') {
            return new Snapshot($body['snapshotId'], $name, 'creating', null, null, now()->toIso8601String());
        }

        return collect($this->snapshots($integration, $serverId))->first(fn (Snapshot $s) => $s->name === $name)
            ?? new Snapshot('', $name, 'creating', null, null, now()->toIso8601String());
    }

    public function deleteSnapshot(Integration $integration, string $snapshotId): void
    {
        $this->client($integration)->delete($this->path($integration).'/snapshot/'.rawurlencode($snapshotId));
    }

    public function delete(Integration $integration, string $id): void
    {
        $this->client($integration)->delete($this->instancePath($integration, $id));
    }

    /** @param  array<string, string>  $credentials */
    private static function clientFor(array $credentials): OVHcloudClient
    {
        return new OVHcloudClient(
            self::endpointOf($credentials),
            $credentials['application_key'] ?? '',
            $credentials['application_secret'] ?? '',
            $credentials['consumer_key'] ?? '',
        );
    }

    /** @param  array<string, string>  $credentials */
    private static function endpointOf(array $credentials): string
    {
        $endpoint = $credentials['endpoint'] ?? '';

        return isset(OVHcloudClient::ENDPOINTS[$endpoint]) ? $endpoint : 'ovh-eu';
    }

    private function client(Integration $integration): OVHcloudClient
    {
        return self::clientFor($integration->credentials);
    }

    private function projectId(Integration $integration): string
    {
        $id = $integration->credential('project');

        return $id !== '' ? $id : (string) ($integration->meta['project'] ?? '');
    }

    private function path(Integration $integration): string
    {
        return 'cloud/project/'.rawurlencode($this->projectId($integration));
    }

    private function instancePath(Integration $integration, string $id): string
    {
        return $this->path($integration).'/instance/'.rawurlencode($id);
    }

    /** @return list<array{id: string, name: string, status: string}> */
    private function projects(OVHcloudClient $client): array
    {
        $projects = [];
        foreach (array_slice(array_values(array_filter($client->get('cloud/project'), 'is_string')), 0, self::MAX_PROJECTS) as $id) {
            $project = $client->get('cloud/project/'.rawurlencode($id));
            $name = $project['description'] ?? $project['projectName'] ?? '';
            $projects[] = [
                'id' => (string) ($project['project_id'] ?? $id),
                'name' => is_string($name) ? trim($name) : '',
                'status' => (string) ($project['status'] ?? 'ok'),
            ];
        }

        return $projects;
    }

    /**
     * The project's Linux flavors in every region, kept for ten minutes.
     *
     * @return list<array{id: string, name: string, region: string, vcpus: int, ram: float, disk: int, type: string, available: bool, resize: bool, linux: bool, hourly: string|null, monthly: string|null}>
     */
    private function flavors(Integration $integration): array
    {
        return Cache::remember("integrations:{$integration->id}:ovh-flavors", now()->addMinutes(10), fn (): array => array_values(array_filter(array_map(
            fn (mixed $row) => is_array($row) ? self::flavorRow($row) : null,
            $this->client($integration)->get($this->path($integration).'/flavor'),
        ), fn (?array $row) => $row !== null && $row['linux'])));
    }

    /**
     * @param  array<mixed>  $flavor
     * @return array{id: string, name: string, region: string, vcpus: int, ram: float, disk: int, type: string, available: bool, resize: bool, linux: bool, hourly: string|null, monthly: string|null}
     */
    private static function flavorRow(array $flavor): array
    {
        $resize = true;
        foreach ((array) ($flavor['capabilities'] ?? []) as $capability) {
            if (is_array($capability) && ($capability['name'] ?? null) === 'resize') {
                $resize = (bool) ($capability['enabled'] ?? false);
            }
        }
        $name = (string) ($flavor['name'] ?? '');
        $codes = (array) ($flavor['planCodes'] ?? []);

        return [
            'id' => (string) ($flavor['id'] ?? ''),
            'name' => $name,
            'region' => (string) ($flavor['region'] ?? ''),
            'vcpus' => (int) ($flavor['vcpus'] ?? 0),
            'ram' => (float) ($flavor['ram'] ?? 0),
            'disk' => (int) ($flavor['disk'] ?? 0),
            'type' => (string) ($flavor['type'] ?? ''),
            'available' => (bool) ($flavor['available'] ?? true),
            'resize' => $resize,
            'linux' => ($flavor['osType'] ?? 'linux') === 'linux' && ! str_starts_with($name, 'win-'),
            'hourly' => is_string($codes['hourly'] ?? null) ? $codes['hourly'] : null,
            'monthly' => is_string($codes['monthly'] ?? null) ? $codes['monthly'] : null,
        ];
    }

    /** @param  array{name: string, type: string}  $flavor */
    private static function gpu(array $flavor): bool
    {
        return str_contains(strtolower($flavor['type']), 'gpu') || preg_match(self::GPU, $flavor['name']) === 1;
    }

    /** @return array{id: string, name: string, region: string, vcpus: int, ram: float, disk: int, type: string, available: bool, resize: bool, linux: bool, hourly: string|null, monthly: string|null}|null */
    private function flavorIn(OVHcloudClient $client, string $path, string $region, string $name): ?array
    {
        foreach ($client->get($path.'/flavor', ['region' => $region]) as $row) {
            if (is_array($row) && ($row['name'] ?? null) === $name && ($row['region'] ?? $region) === $region) {
                $flavor = self::flavorRow($row);
                if ($flavor['linux']) {
                    return $flavor;
                }
            }
        }

        return null;
    }

    /**
     * The project's regions, with where they are and whether instances can
     * be made there now. They change rarely, so they're kept for a day.
     *
     * @return list<array{name: string, city: string, country: string, zone: string|null, up: bool}>
     */
    private function regionRows(Integration $integration): array
    {
        return Cache::remember("integrations:{$integration->id}:ovh-regions", now()->addDay(), function () use ($integration): array {
            $client = $this->client($integration);
            $rows = [];
            foreach (array_filter($client->get($this->path($integration).'/region'), 'is_string') as $name) {
                $region = $client->get($this->path($integration).'/region/'.rawurlencode($name));
                $instances = null;
                foreach ((array) ($region['services'] ?? []) as $service) {
                    if (is_array($service) && ($service['name'] ?? null) === 'instance') {
                        $instances = ($service['status'] ?? null) === 'UP';
                    }
                }
                $rows[] = [
                    'name' => $name,
                    'city' => self::city((string) ($region['datacenterLocation'] ?? ''), $name),
                    'country' => strtoupper((string) ($region['countryCode'] ?? '')),
                    'zone' => isset($region['continentCode']) ? (string) $region['continentCode'] : null,
                    'up' => ($region['status'] ?? 'UP') === 'UP' && $instances !== false,
                ];
            }

            return $rows;
        });
    }

    /** @return array<string, ServerLocation> */
    private function regions(Integration $integration): array
    {
        $regions = [];
        foreach ($this->regionRows($integration) as $row) {
            $regions[$row['name']] = new ServerLocation($row['name'], $row['city'], $row['country'], $row['zone']);
        }

        return $regions;
    }

    private static function city(string $location, string $region): string
    {
        $location = strtoupper($location);
        $parts = explode('-', $location);
        $letters = preg_match('/^[A-Z]+/', strtoupper($region), $m) === 1 ? $m[0] : '';

        return self::PLACES[$location] ?? self::PLACES[end($parts)] ?? self::PLACES[$letters] ?? ($location !== '' ? $location : $region);
    }

    /**
     * Prices by plan code from OVHcloud's public catalogue (no keys needed),
     * kept for a day. When the catalogue can't be read, types have no price.
     *
     * @return array{currency: string|null, prices: array<string, array{hourly?: float, monthly?: float}>}
     */
    private function catalogue(Integration $integration): array
    {
        $endpoint = self::endpointOf($integration->credentials);
        $subsidiary = is_string($integration->meta['subsidiary'] ?? null) ? $integration->meta['subsidiary'] : self::SUBSIDIARIES[$endpoint];

        try {
            return Cache::remember("integrations:ovhcloud:catalogue:{$endpoint}:{$subsidiary}", now()->addDay(), function () use ($integration, $subsidiary): array {
                $catalogue = $this->client($integration)->anonymous()->get('order/catalog/public/cloud', ['ovhSubsidiary' => $subsidiary]);
                $prices = [];
                foreach ([...(array) ($catalogue['addons'] ?? []), ...(array) ($catalogue['plans'] ?? [])] as $plan) {
                    if (! is_array($plan) || ! is_string($plan['planCode'] ?? null)) {
                        continue;
                    }
                    $code = $plan['planCode'];
                    foreach ((array) ($plan['pricings'] ?? []) as $pricing) {
                        if (! is_array($pricing) || ! is_numeric($pricing['price'] ?? null)) {
                            continue;
                        }
                        $unit = (string) ($pricing['intervalUnit'] ?? '');
                        $kind = match (true) {
                            $unit === 'hour' => 'hourly',
                            $unit === 'month' => 'monthly',
                            str_contains($code, 'consumption') => 'hourly',
                            str_contains($code, 'monthly') => 'monthly',
                            default => null,
                        };
                        if ($kind !== null && ! isset($prices[$code][$kind])) {
                            $prices[$code][$kind] = (float) $pricing['price'] / 100_000_000;
                        }
                    }
                }
                $currency = $catalogue['locale']['currencyCode'] ?? null;

                return ['currency' => is_string($currency) ? $currency : null, 'prices' => $prices];
            });
        } catch (IntegrationFailed) {
            return ['currency' => null, 'prices' => []];
        }
    }

    /**
     * What an instance made by ServerOS costs: it's billed by the hour, so a
     * month is 730 hours of it.
     *
     * @param  array{hourly: string|null, monthly: string|null}  $flavor
     * @param  array<string, array{hourly?: float, monthly?: float}>  $prices
     * @return array{monthly: float, hourly: float}|null
     */
    private static function priceOf(array $flavor, array $prices): ?array
    {
        $hourly = $flavor['hourly'] === null ? null : ($prices[$flavor['hourly']]['hourly'] ?? null);
        if ($hourly !== null) {
            return ['monthly' => round($hourly * self::HOURS_A_MONTH, 2), 'hourly' => $hourly];
        }
        $monthly = $flavor['monthly'] === null ? null : ($prices[$flavor['monthly']]['monthly'] ?? null);

        return $monthly === null ? null : ['monthly' => $monthly, 'hourly' => round($monthly / self::HOURS_A_MONTH, 4)];
    }

    /**
     * Ubuntu and Debian, newest first, with the newest Ubuntu LTS picked.
     * Images have an id per region, so they're named by their name.
     *
     * @return list<ServerImage>
     */
    private function images(OVHcloudClient $client, string $path): array
    {
        $found = [];
        foreach ($client->get($path.'/image', ['osType' => 'linux']) as $image) {
            if (is_array($image) && ($image['flavorType'] ?? null) === null && ($image['status'] ?? 'active') === 'active'
                && ($image['visibility'] ?? 'public') === 'public' && preg_match(self::SYSTEM, (string) ($image['name'] ?? ''), $m) === 1) {
                $found[$m[0]] = ['name' => $m[0], 'os' => strtolower($m[1]), 'version' => $m[2]];
            }
        }
        $found = array_values($found);
        usort($found, fn (array $a, array $b) => [$a['os'] !== 'ubuntu'] <=> [$b['os'] !== 'ubuntu'] ?: version_compare($b['version'], $a['version']));

        $lts = null;
        foreach ($found as $image) {
            if ($lts === null && $image['os'] === 'ubuntu' && preg_match('/^(\d+)\.04$/', $image['version'], $m) === 1 && (int) $m[1] % 2 === 0) {
                $lts = $image['name'];
            }
        }

        return array_map(fn (array $image) => new ServerImage($image['name'], $image['name'], $image['os'], $image['version'], 'x86', $image['name'] === $lts), $found);
    }

    /** @return list<string> the account's dedicated servers, or none when the keys can't read them */
    private function dedicatedNames(OVHcloudClient $client): array
    {
        try {
            return array_values(array_filter($client->get('dedicated/server'), 'is_string'));
        } catch (IntegrationFailed $e) {
            if (in_array($e->reason, [IntegrationFailed::PERMISSION, IntegrationFailed::NOT_FOUND], true)) {
                return [];
            }
            throw $e;
        }
    }

    private function dedicatedServer(Integration $integration, OVHcloudClient $client, string $name): ?Server
    {
        $path = 'dedicated/server/'.rawurlencode($name);
        try {
            $server = $client->get($path);
        } catch (IntegrationFailed $e) {
            if (in_array($e->reason, [IntegrationFailed::PERMISSION, IntegrationFailed::NOT_FOUND], true)) {
                return null;
            }
            throw $e;
        }

        $hardware = Cache::remember("integrations:{$integration->id}:ovh-dedicated:".sha1($name), now()->addDay(), function () use ($client, $path): array {
            $read = function (string $part) use ($client, $path): array {
                try {
                    return $client->get($path.'/'.$part);
                } catch (IntegrationFailed) {
                    return [];
                }
            };
            $specs = $read('specifications/hardware');
            $ipv6 = null;
            foreach ($read('ips') as $block) {
                if (is_string($block) && str_contains($block, ':')) {
                    $ipv6 ??= $block;
                }
            }
            $disk = 0.0;
            foreach ((array) ($specs['diskGroups'] ?? []) as $group) {
                if (is_array($group)) {
                    $disk += (int) ($group['numberOfDisks'] ?? 1) * self::gigabytes($group['diskSize'] ?? null);
                }
            }

            return [
                'label' => is_string($specs['description'] ?? null) ? $specs['description'] : null,
                'cores' => (int) ($specs['numberOfProcessors'] ?? 1) * (int) ($specs['coresPerProcessor'] ?? 0),
                'memoryGb' => round(self::gigabytes($specs['memorySize'] ?? null), 2),
                'diskGb' => (int) round($disk),
                'architecture' => str_contains(strtolower((string) ($specs['processorArchitecture'] ?? '')), 'arm') ? 'arm' : 'x86',
                'ipv6' => $ipv6,
            ];
        });

        $datacenter = strtolower((string) ($server['datacenter'] ?? ''));
        $letters = preg_match('/^[a-z]+/', $datacenter, $m) === 1 ? $m[0] : '';
        $range = (string) ($server['commercialRange'] ?? '');
        $ip = is_string($server['ip'] ?? null) && filter_var($server['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $server['ip'] : null;

        return new Server(
            id: self::DEDICATED.$name,
            name: (string) ($server['name'] ?? $name),
            status: match (true) {
                in_array($server['state'] ?? 'ok', ['error', 'hacked', 'hackedBlocked'], true) => 'other',
                ($server['powerState'] ?? null) === 'poweron' => 'running',
                ($server['powerState'] ?? null) === 'poweroff' => 'off',
                default => 'other',
            },
            type: $range,
            typeLabel: $hardware['label'] ?? ($range === '' ? self::t('Dedicated server') : $range),
            cores: $hardware['cores'],
            memoryGb: $hardware['memoryGb'],
            diskGb: $hardware['diskGb'],
            location: $datacenter,
            city: self::PLACES[strtoupper($letters)] ?? null,
            country: self::COUNTRIES[$letters] ?? null,
            ipv4: $ip,
            ipv6: $hardware['ipv6'],
            architecture: $hardware['architecture'],
        );
    }

    private static function gigabytes(mixed $size): float
    {
        if (! is_array($size) || ! is_numeric($size['value'] ?? null)) {
            return 0;
        }

        return (float) $size['value'] * match (strtoupper((string) ($size['unit'] ?? 'GB'))) {
            'TB' => 1000,
            'MB' => 1 / 1024,
            default => 1,
        };
    }

    /**
     * @param  array<mixed>  $i
     * @param  array{name: string, vcpus: int, ram: float, disk: int}|null  $flavor
     * @param  array<string, ServerLocation>  $regions
     */
    private function toServer(array $i, ?array $flavor, array $regions): Server
    {
        $address = function (int $version) use ($i): ?string {
            foreach ((array) ($i['ipAddresses'] ?? []) as $ip) {
                if (is_array($ip) && ($ip['type'] ?? null) === 'public' && (int) ($ip['version'] ?? 0) === $version && is_string($ip['ip'] ?? null)) {
                    return $ip['ip'];
                }
            }

            return null;
        };
        $ipv6 = $address(6);
        $region = $regions[(string) ($i['region'] ?? '')] ?? null;
        $status = (string) ($i['status'] ?? '');

        return new Server(
            id: (string) ($i['id'] ?? ''),
            name: (string) ($i['name'] ?? ''),
            status: match (true) {
                in_array($status, ['ACTIVE', 'VERIFY_RESIZE', 'SNAPSHOTTING'], true) => 'running',
                in_array($status, ['BUILD', 'BUILDING', 'REBOOT', 'HARD_REBOOT', 'REBUILD', 'RESUMING', 'UNSHELVING', 'PASSWORD'], true) => 'starting',
                in_array($status, ['SHUTOFF', 'STOPPED', 'SHELVED', 'SHELVED_OFFLOADED', 'SUSPENDED', 'PAUSED'], true) => 'off',
                $status === 'SHELVING' => 'stopping',
                in_array($status, ['DELETING', 'DELETED', 'SOFT_DELETED'], true) => 'deleting',
                default => 'other',
            },
            type: $flavor['name'] ?? '',
            typeLabel: $flavor['name'] ?? '',
            cores: $flavor['vcpus'] ?? 0,
            memoryGb: $flavor['ram'] ?? 0,
            diskGb: $flavor['disk'] ?? 0,
            location: (string) ($i['region'] ?? ''),
            city: $region?->city,
            country: $region === null || $region->country === '' ? null : $region->country,
            ipv4: $address(4),
            ipv6: $ipv6 === null ? null : $ipv6.'/128',
            architecture: 'x86',
            createdAt: isset($i['created']) ? (string) $i['created'] : null,
        );
    }

    /** @param  array<mixed>  $image */
    private function toSnapshot(array $image): Snapshot
    {
        $size = is_numeric($image['size'] ?? null) && (float) $image['size'] > 0 ? round((float) $image['size'], 2) : null;
        $disk = is_numeric($image['minDisk'] ?? null) && (int) $image['minDisk'] > 0 ? (int) $image['minDisk'] : null;

        return new Snapshot(
            id: (string) ($image['id'] ?? ''),
            name: (string) ($image['name'] ?? ''),
            status: match ($image['status'] ?? null) {
                'active' => 'available',
                'queued', 'saving', 'uploading', 'importing', null => 'creating',
                default => (string) $image['status'],
            },
            sizeGb: $size,
            diskGb: $disk,
            createdAt: isset($image['creationDate']) ? (string) $image['creationDate'] : null,
        );
    }
}
