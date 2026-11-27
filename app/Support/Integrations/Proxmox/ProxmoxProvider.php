<?php

namespace App\Support\Integrations\Proxmox;

use App\Models\Integration;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;
use App\Support\Integrations\Servers\ActionState;
use App\Support\Integrations\Servers\CommandRun;
use App\Support\Integrations\Servers\LimitsServers;
use App\Support\Integrations\Servers\ManagesServers;
use App\Support\Integrations\Servers\NewServer;
use App\Support\Integrations\Servers\PreparesServers;
use App\Support\Integrations\Servers\RunsCommands;
use App\Support\Integrations\Servers\Server;
use App\Support\Integrations\Servers\ServerImage;
use App\Support\Integrations\Servers\ServerLocation;
use App\Support\Integrations\Servers\ServerPlans;
use App\Support\Integrations\Servers\ServerRules;
use App\Support\Integrations\Servers\ServerSize;
use App\Support\Integrations\Servers\SizeChange;
use App\Support\Integrations\Servers\SizeLimits;
use App\Support\Integrations\Servers\SizesFreely;
use App\Support\Integrations\Servers\Snapshot;
use App\Support\Integrations\Servers\StartedServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * Proxmox VE, one node or a whole cluster. QEMU VMs are servers ServerOS
 * manages; LXC containers are listed and linked, but read only.
 *
 * A server's id is its VMID, which is unique in a cluster; the node it's on
 * is looked up for each call, since VMs move between nodes. Actions are
 * Proxmox tasks, followed by their UPID. Snapshot ids are "vmid-name"
 * (names start with a letter), since Proxmox names snapshots per VM.
 *
 * Proxmox can't take cloud-init user data through its API (snippets can't
 * be uploaded), so a new VM is cloned from a template, given its size and
 * cloud-init user, keys and network, started, and ServerOS is installed
 * through the QEMU guest agent (RunsCommands).
 */
final class ProxmoxProvider extends Provider implements LimitsServers, ManagesServers, PreparesServers, RunsCommands, SizesFreely
{
    private const string SKIP_INTERFACES = '/^(?:lo|docker\d*|br-|veth|virbr|cni|flannel|cali|kube|tun|tap|wg|tailscale|zt)/';

    /** What ServerOS asks a token for, and the guest agent's own privilege before Proxmox VE 9. */
    private const array PRIVILEGES = ['VM.Audit', 'VM.PowerMgmt', 'VM.Config.CPU', 'VM.Config.Memory', 'VM.Config.Disk', 'VM.Config.Cloudinit', 'VM.Snapshot', 'VM.Clone', 'VM.Allocate', 'Datastore.AllocateSpace'];

    public function key(): string
    {
        return 'proxmox';
    }

    public function name(): string
    {
        return 'Proxmox';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'url',
                label: self::t('Address'),
                secret: false,
                help: self::t('The https address you open Proxmox VE at. Port 8006 unless you changed it. Any node of a cluster works.'),
                placeholder: 'https://pve.example.com:8006',
                rules: ['required', 'string', 'max:200'],
            ),
            new ConnectField(
                key: 'token',
                label: self::t('API token'),
                help: self::t('In Proxmox, open Datacenter › Permissions › API Tokens and add one. Give it the PVEVMAdmin role on / (or untick Privilege Separation), and paste it as user@realm!tokenid=secret.'),
                placeholder: 'serveros@pve!panel=…',
                rules: ['required', 'string', 'max:300'],
            ),
            new ConnectField(
                key: 'fingerprint',
                label: self::t('Certificate fingerprint'),
                secret: false,
                help: self::t('Proxmox makes its own certificate, so pin it: open the node, then System › Certificates, and copy the SHA-256 fingerprint of pve-ssl.pem. Leave it empty if the certificate is from a trusted authority.'),
                placeholder: 'AB:CD:EF:…',
                rules: ['nullable', 'string', 'max:200'],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::Servers, Capability::CreateServers, Capability::Resize, Capability::Power, Capability::Snapshots, Capability::DeleteServers];
    }

    public function capabilitiesOf(Server $server): array
    {
        return $server->type === 'lxc' ? [Capability::Servers] : $this->capabilities();
    }

    public function describe(Capability $capability): string
    {
        return match ($capability) {
            Capability::Servers => self::t('Lists the VMs and containers on every node, and matches them to your machines by the addresses the guest agent reports. Containers are read only.'),
            Capability::CreateServers => self::t('Clones a VM from a template you pick, sets its size and cloud-init, starts it and installs ServerOS through the QEMU guest agent. Without the agent, you get one command to run on it.'),
            Capability::Resize => self::t('Changes a linked VM’s cores and memory, live where Proxmox can and with a restart where it can’t, and grows its disk.'),
            Capability::Power => self::t('Restarts a linked VM, or stops and starts it, even when its daemon is offline.'),
            Capability::Snapshots => self::t('Takes, lists and deletes snapshots of a linked VM. Roll back from Proxmox.'),
            Capability::DeleteServers => self::t('Stops a linked VM and destroys it with its disks, and takes it out of backup jobs.'),
            default => parent::describe($capability),
        };
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $address = ProxmoxClient::normalise($credentials['url'] ?? null)
            ?? throw new IntegrationFailed(self::t('That isn’t a Proxmox address. Use the https:// address you open it at, like https://pve.example.com:8006.'), field: 'url');
        $token = ProxmoxClient::token($credentials['token'] ?? null)
            ?? throw new IntegrationFailed(self::t('That isn’t a Proxmox API token. Paste it as user@realm!tokenid=secret.'), field: 'token');
        $fingerprint = null;
        if (trim((string) ($credentials['fingerprint'] ?? '')) !== '') {
            $fingerprint = ProxmoxClient::fingerprint($credentials['fingerprint'])
                ?? throw new IntegrationFailed(self::t('That isn’t a SHA-256 fingerprint. It’s 64 hex digits, in pairs like AB:CD:EF.'), field: 'fingerprint');
        }

        $client = new ProxmoxClient($address, $token, $fingerprint);
        $version = $client->read('version');
        if (! is_array($version) || ! is_string($version['version'] ?? null)) {
            throw new IntegrationFailed(self::t('That address answered, but not the way Proxmox VE does. Check the address and port.'), field: 'url');
        }
        $nodes = array_values(array_filter((array) $client->read('nodes'), 'is_array'));

        $cluster = null;
        try {
            foreach ((array) $client->read('cluster/status') as $entry) {
                if (is_array($entry) && ($entry['type'] ?? null) === 'cluster' && is_string($entry['name'] ?? null)) {
                    $cluster = $entry['name'];
                }
            }
        } catch (IntegrationFailed $e) {
            if ($e->refusedCredentials()) {
                throw $e;
            }
        }

        $missing = $this->missingPrivileges($client, (string) $version['version']);
        if (in_array('VM.Audit', $missing, true)) {
            throw new IntegrationFailed(self::t('This token can’t see any VMs. Give it the PVEVMAdmin role on /, or untick Privilege Separation.'), IntegrationFailed::PERMISSION, null, 'token');
        }

        $guests = array_values(array_filter((array) $client->read('cluster/resources', ['type' => 'vm']), 'is_array'));
        $count = fn (string $type, int $template): int => count(array_filter($guests, fn (array $g) => ($g['type'] ?? null) === $type && (int) ($g['template'] ?? 0) === $template));
        $firstNode = is_string($nodes[0]['node'] ?? null) ? $nodes[0]['node'] : null;

        return new ConnectedAccount(
            $cluster ?? (count($nodes) === 1 && $firstNode !== null ? $firstNode : ProxmoxClient::host($address)),
            [
                'address' => ProxmoxClient::host($address).':'.ProxmoxClient::port($address),
                'version' => (string) $version['version'],
                'cluster' => $cluster,
                'nodes' => count($nodes),
                'vms' => $count('qemu', 0),
                'containers' => $count('lxc', 0),
                'templates' => $count('qemu', 1),
                'servers' => $count('qemu', 0) + $count('lxc', 0),
                'token' => ProxmoxClient::tokenId($token),
                'pinned' => $fingerprint !== null,
                'fingerprint' => $fingerprint === null ? null : ProxmoxClient::showFingerprint($fingerprint),
                'missing' => $missing === [] ? null : implode(', ', $missing),
            ],
            ['url' => $address, 'token' => $token, 'fingerprint' => $fingerprint ?? ''],
        );
    }

    public function summary(Integration $integration): string
    {
        $nodes = (int) ($integration->meta['nodes'] ?? 0);
        $servers = (int) ($integration->meta['servers'] ?? 0);

        return ($nodes === 1 ? self::t('1 node') : self::t(':count nodes', ['count' => $nodes]))
            .' · '.($servers === 1 ? self::t('1 server') : self::t(':count servers', ['count' => $servers]));
    }

    public function facts(Integration $integration): array
    {
        $meta = $integration->meta ?? [];
        $types = $integration->servers()->pluck('details')->map(fn ($d) => is_array($d) ? ($d['type'] ?? null) : null)->countBy();

        return array_values(array_filter([
            is_string($meta['address'] ?? null) ? ['label' => self::t('Address'), 'value' => $meta['address']] : null,
            is_string($meta['version'] ?? null) ? ['label' => self::t('Proxmox VE'), 'value' => $meta['version']] : null,
            ['label' => self::t('Cluster'), 'value' => is_string($meta['cluster'] ?? null) ? $meta['cluster'] : self::t('Single node')],
            ['label' => self::t('Nodes'), 'value' => (string) (int) ($meta['nodes'] ?? 0)],
            ['label' => self::t('VMs'), 'value' => (string) (int) ($types['qemu'] ?? $meta['vms'] ?? 0)],
            ['label' => self::t('Containers'), 'value' => (string) (int) ($types['lxc'] ?? $meta['containers'] ?? 0)],
            is_string($meta['token'] ?? null) ? ['label' => self::t('Token ID'), 'value' => $meta['token']] : null,
            ['label' => self::t('Certificate'), 'value' => ($meta['pinned'] ?? false) && is_string($meta['fingerprint'] ?? null)
                ? self::t('Pinned, ending :end', ['end' => substr($meta['fingerprint'], -11)])
                : self::t('Trusted authority')],
            is_string($meta['missing'] ?? null) ? ['label' => self::t('Missing privileges'), 'value' => $meta['missing']] : null,
        ]));
    }

    public function usage(Integration $integration): array
    {
        $containers = $integration->servers()->get()->filter(fn ($row) => ($row->details['type'] ?? null) === 'lxc')->count();
        $made = $integration->operations()->where('kind', 'create')->whereIn('state', ['queued', 'running'])->count();
        $out = [];
        if ($containers > 0) {
            $out[] = $containers === 1
                ? self::t('1 container is listed read only. ServerOS doesn’t change containers.')
                : self::t(':count containers are listed read only. ServerOS doesn’t change containers.', ['count' => $containers]);
        }
        if ($made > 0) {
            $out[] = $made === 1 ? self::t('1 new VM is being made here.') : self::t(':count new VMs are being made here.', ['count' => $made]);
        }

        return $out;
    }

    public function servers(Integration $integration): array
    {
        $client = $this->client($integration);
        $servers = [];
        foreach ($this->guests($client) as $guest) {
            if ((int) ($guest['template'] ?? 0) === 0 && in_array($guest['type'] ?? null, ['qemu', 'lxc'], true)) {
                $servers[] = $this->toServer($integration, $client, $guest);
            }
        }

        return $servers;
    }

    public function server(Integration $integration, string $id): ?Server
    {
        $client = $this->client($integration);
        $guest = $this->guest($client, $id);

        return $guest === null || (int) ($guest['template'] ?? 0) === 1 ? null : $this->toServer($integration, $client, $guest);
    }

    public function plans(Integration $integration): ServerPlans
    {
        return ServerPlans::fromArray(Cache::remember("integrations:{$integration->id}:plans", now()->addMinutes(10), function () use ($integration): array {
            $client = $this->client($integration);
            $locations = [];
            foreach ((array) $client->read('nodes') as $node) {
                if (is_array($node) && is_string($node['node'] ?? null) && ($node['status'] ?? 'online') === 'online') {
                    $locations[] = new ServerLocation($node['node'], $node['node'], '');
                }
            }

            $images = [];
            foreach ($this->guests($client) as $guest) {
                if (($guest['type'] ?? null) !== 'qemu' || (int) ($guest['template'] ?? 0) !== 1) {
                    continue;
                }
                $node = (string) ($guest['node'] ?? '');
                $vmid = (string) ($guest['vmid'] ?? '');
                $config = (array) $client->read("nodes/{$node}/qemu/{$vmid}/config");
                $disk = self::bootDisk($config);
                $images[] = new ServerImage(
                    id: $vmid,
                    label: (string) ($guest['name'] ?? "VM {$vmid}"),
                    os: str_starts_with((string) ($config['ostype'] ?? 'l26'), 'w') ? 'windows' : 'linux',
                    version: null,
                    architecture: ($config['arch'] ?? 'x86_64') === 'aarch64' ? 'arm' : 'x86',
                    location: $node,
                    size: new ServerSize(self::vcpus($config), round(self::memoryMb($config) / 1024, 2), $disk[1] ?? (int) round((float) ($guest['maxdisk'] ?? 0) / 1024 ** 3)),
                    agent: self::agentOn($config['agent'] ?? null),
                    cloudInit: self::hasCloudInit($config),
                );
            }
            usort($images, fn (ServerImage $a, ServerImage $b) => [! ($a->agent && $a->cloudInit), ! $a->cloudInit, $a->label] <=> [! ($b->agent && $b->cloudInit), ! $b->cloudInit, $b->label]);
            if ($images !== [] && $images[0]->cloudInit) {
                $first = $images[0];
                $images[0] = new ServerImage($first->id, $first->label, $first->os, $first->version, $first->architecture, true, $first->location, $first->size, $first->agent, $first->cloudInit);
            }

            return (new ServerPlans($locations, [], $images, [], ''))->toArray();
        }));
    }

    public function create(Integration $integration, NewServer $server, string $userData): StartedServer
    {
        $client = $this->client($integration);
        $template = $this->guest($client, $server->image);
        if ($template === null || (int) ($template['template'] ?? 0) !== 1 || ($template['type'] ?? null) !== 'qemu') {
            throw new IntegrationFailed(self::t('That template isn’t in Proxmox any more. Pick another.'), IntegrationFailed::NOT_FOUND);
        }
        $node = (string) $template['node'];
        $vmid = (string) $client->read('cluster/nextid');
        if (! ctype_digit($vmid)) {
            throw new IntegrationFailed(self::t('Proxmox didn’t give a free VM ID. Try again in a minute.'));
        }

        $upid = $client->task('POST', "nodes/{$node}/qemu/{$server->image}/clone", [
            'newid' => (int) $vmid,
            'name' => $server->name,
            'full' => 1,
            'description' => 'Made from ServerOS.',
        ]);
        $size = $server->size ?? new ServerSize(self::vcpus([]), 2, (int) round((float) ($template['maxdisk'] ?? 0) / 1024 ** 3));

        return new StartedServer(new Server(
            id: $vmid,
            name: $server->name,
            status: 'starting',
            type: 'qemu',
            typeLabel: self::t('VM'),
            cores: $size->cores,
            memoryGb: $size->memoryGb,
            diskGb: $size->diskGb,
            location: $node,
            city: null,
            country: null,
            ipv4: null,
            ipv6: null,
            architecture: 'x86',
        ), $upid);
    }

    public function prepare(Integration $integration, string $id, NewServer $server): ?string
    {
        [$client, $node] = $this->locate($integration, $id);
        $path = "nodes/{$node}/qemu/{$id}";
        $config = (array) $client->read("{$path}/config");

        $changes = [];
        if ($server->size !== null) {
            $changes += $this->cpuChange($config, $server->size->cores);
            if ($server->size->memoryMb() !== self::memoryMb($config)) {
                $changes['memory'] = $server->size->memoryMb();
            }
        }
        if ($server->user !== null && $server->user !== '') {
            $changes['ciuser'] = $server->user;
        }
        if ($server->publicKeys !== []) {
            $changes['sshkeys'] = rawurlencode(implode("\n", $server->publicKeys));
        }
        $changes['ipconfig0'] = $server->address === null ? 'ip=dhcp' : "ip={$server->address},gw={$server->gateway}";
        $client->put("{$path}/config", $changes);

        $disk = self::bootDisk($config);
        if ($server->size !== null && $disk !== null && $server->size->diskGb > $disk[1]) {
            $this->waitFor($client, $client->task('PUT', "{$path}/resize", ['disk' => $disk[0], 'size' => $server->size->diskGb.'G']), 60);
        }

        return $client->task('POST', "{$path}/status/start");
    }

    public function resizeTypes(Integration $integration, Server $server): array
    {
        return [];
    }

    public function resize(Integration $integration, string $id, string $type, bool $growDisk): ?string
    {
        throw new IntegrationFailed(self::t('Proxmox VMs are resized by size, not by type.'));
    }

    public function sizeLimits(Integration $integration, Server $server): SizeLimits
    {
        [$client, $node] = $this->locate($integration, $server->id);
        $config = (array) $client->read("nodes/{$node}/qemu/{$server->id}/config");
        $host = collect((array) $client->read('nodes'))->first(fn ($n) => is_array($n) && ($n['node'] ?? null) === $node);
        $hotplug = (string) ($config['hotplug'] ?? 'network,disk,usb');
        $disk = self::bootDisk($config);

        return new SizeLimits(
            maxCores: max(1, (int) ($host['maxcpu'] ?? 512)),
            maxMemoryGb: isset($host['maxmem']) ? floor((float) $host['maxmem'] / 1024 ** 3) : 4096.0,
            minDiskGb: $disk[1] ?? $server->diskGb,
            liveCores: false,
            liveMemory: (bool) preg_match('/(?:^|,)memory(?:,|$)/', $hotplug) && (string) ($config['numa'] ?? '0') === '1',
        );
    }

    public function resizeTo(Integration $integration, string $id, ServerSize $size, bool $restart): SizeChange
    {
        [$client, $node, $guest] = $this->locate($integration, $id);
        $path = "nodes/{$node}/qemu/{$id}";
        $config = (array) $client->read("{$path}/config");

        $disk = self::bootDisk($config);
        if ($disk !== null && $size->diskGb > $disk[1]) {
            $this->waitFor($client, $client->task('PUT', "{$path}/resize", ['disk' => $disk[0], 'size' => $size->diskGb.'G']), 60);
        }

        $changes = $this->cpuChange($config, $size->cores);
        if ($size->memoryMb() !== self::memoryMb($config)) {
            $changes['memory'] = $size->memoryMb();
        }
        if ($changes === []) {
            return new SizeChange;
        }
        $client->put("{$path}/config", $changes);

        if (($guest['status'] ?? null) !== 'running') {
            return new SizeChange;
        }
        $pending = false;
        foreach ((array) $client->read("{$path}/pending") as $entry) {
            if (is_array($entry) && in_array($entry['key'] ?? null, ['cores', 'sockets', 'memory'], true) && array_key_exists('pending', $entry)) {
                $pending = true;
            }
        }
        if (! $pending) {
            return new SizeChange;
        }

        return $restart ? new SizeChange($client->task('POST', "{$path}/status/reboot")) : new SizeChange(null, false);
    }

    public function rules(): ServerRules
    {
        return new ServerRules(
            resizeNeedsPowerOff: false,
            resizeDisk: ServerRules::DISK_OPTIONAL,
            resizeDown: true,
            gracefulRestart: true,
            snapshotsPerServer: true,
            sizing: ServerRules::SIZING_CUSTOM,
            powerCycleSteps: true,
        );
    }

    public function shutdown(Integration $integration, string $id): ?string
    {
        return $this->power($integration, $id, 'shutdown');
    }

    public function powerOff(Integration $integration, string $id): ?string
    {
        return $this->power($integration, $id, 'stop');
    }

    public function powerOn(Integration $integration, string $id): ?string
    {
        return $this->power($integration, $id, 'start');
    }

    public function reboot(Integration $integration, string $id): ?string
    {
        return $this->power($integration, $id, 'reboot');
    }

    // rules() has powerCycleSteps, so ServerOperations stops it and starts it again; this is the short way for anything else.
    public function powerCycle(Integration $integration, string $id): ?string
    {
        [$client, $node, $guest] = $this->locate($integration, $id);
        if (($guest['status'] ?? null) === 'running') {
            $this->waitFor($client, $client->task('POST', "nodes/{$node}/qemu/{$id}/status/stop"), 30);
        }

        return $client->task('POST', "nodes/{$node}/qemu/{$id}/status/start");
    }

    public function action(Integration $integration, string $actionId): ActionState
    {
        $node = ProxmoxClient::nodeOfTask($actionId) ?? throw new IntegrationFailed(self::t('Something went wrong on ServerOS’s side. Check the server in the provider’s console.'));

        return self::taskState((array) $this->client($integration)->read("nodes/{$node}/tasks/".rawurlencode($actionId).'/status'));
    }

    public function snapshots(Integration $integration, string $serverId): array
    {
        [$client, $node] = $this->locate($integration, $serverId);
        $path = "nodes/{$node}/qemu/{$serverId}";
        $snapshots = [];
        foreach ((array) $client->read("{$path}/snapshot") as $s) {
            if (! is_array($s) || ! is_string($s['name'] ?? null) || $s['name'] === 'current') {
                continue;
            }
            $snapshots[] = new Snapshot(
                id: $serverId.'-'.$s['name'],
                name: is_string($s['description'] ?? null) && trim($s['description']) !== '' ? trim($s['description']) : $s['name'],
                status: 'available',
                sizeGb: null,
                diskGb: null,
                createdAt: is_numeric($s['snaptime'] ?? null) ? date(DATE_ATOM, (int) $s['snaptime']) : null,
            );
        }
        usort($snapshots, fn (Snapshot $a, Snapshot $b) => strcmp((string) $b->createdAt, (string) $a->createdAt));

        if ($snapshots !== [] && (((array) $client->read("{$path}/status/current"))['lock'] ?? null) === 'snapshot') {
            $newest = $snapshots[0];
            $snapshots[0] = new Snapshot($newest->id, $newest->name, 'creating', null, null, $newest->createdAt);
        }

        return $snapshots;
    }

    public function snapshot(Integration $integration, string $serverId, string $name): Snapshot
    {
        [$client, $node] = $this->locate($integration, $serverId);
        $path = "nodes/{$node}/qemu/{$serverId}/snapshot";
        $taken = array_values(array_map(fn ($s) => is_array($s) ? (string) ($s['name'] ?? '') : '', (array) $client->read($path)));
        $snapname = self::snapName($name, $taken);
        $client->task('POST', $path, ['snapname' => $snapname, 'description' => $name]);

        return new Snapshot($serverId.'-'.$snapname, $name, 'creating', null, null, now()->toAtomString());
    }

    public function deleteSnapshot(Integration $integration, string $snapshotId): void
    {
        if (preg_match('/^(\d+)-([A-Za-z][A-Za-z0-9_-]*)$/', $snapshotId, $m) !== 1) {
            throw new IntegrationFailed(self::t('Proxmox can’t find it. It may have been deleted there.'), IntegrationFailed::NOT_FOUND);
        }
        [, $vmid, $snapname] = $m;
        [$client, $node] = $this->locate($integration, $vmid);
        $client->task('DELETE', "nodes/{$node}/qemu/{$vmid}/snapshot/".rawurlencode($snapname));
    }

    // Proxmox only destroys a stopped VM. purge also takes it out of backup jobs, replication and HA.
    public function delete(Integration $integration, string $id): void
    {
        [$client, $node, $guest] = $this->locate($integration, $id);
        if (($guest['status'] ?? null) !== 'stopped') {
            $this->waitFor($client, $client->task('POST', "nodes/{$node}/qemu/{$id}/status/stop"), 60);
        }
        $client->task('DELETE', "nodes/{$node}/qemu/{$id}?purge=1&destroy-unreferenced-disks=1");
    }

    public function runner(Integration $integration, string $id): string
    {
        [$client, $node, $guest] = $this->locate($integration, $id);
        $config = (array) $client->read("nodes/{$node}/qemu/{$id}/config");
        if (! self::agentOn($config['agent'] ?? null)) {
            return self::NEVER;
        }
        if (($guest['status'] ?? null) !== 'running') {
            return self::WAITING;
        }
        try {
            $client->post("nodes/{$node}/qemu/{$id}/agent/ping");
        } catch (IntegrationFailed $e) {
            if ($e->reason === IntegrationFailed::PERMISSION) {
                return self::NEVER;
            }

            return self::WAITING;
        }

        return self::READY;
    }

    public function run(Integration $integration, string $id, string $script): string
    {
        [$client, $node] = $this->locate($integration, $id);
        $body = $client->post("nodes/{$node}/qemu/{$id}/agent/exec", ['command' => ['bash', '-s'], 'input-data' => $script]);
        $pid = $body['data']['pid'] ?? null;

        return is_numeric($pid) ? (string) $pid : throw new IntegrationFailed(self::t('The guest agent didn’t start the install. Run it on the VM yourself.'));
    }

    public function ran(Integration $integration, string $id, string $handle): ?CommandRun
    {
        [$client, $node] = $this->locate($integration, $id);
        $status = (array) $client->read("nodes/{$node}/qemu/{$id}/agent/exec-status", ['pid' => $handle]);
        if (! (bool) ($status['exited'] ?? false)) {
            return null;
        }

        return new CommandRun((int) ($status['exitcode'] ?? 1), trim((string) ($status['out-data'] ?? '')."\n".(string) ($status['err-data'] ?? '')));
    }

    private function client(Integration $integration): ProxmoxClient
    {
        $fingerprint = $integration->credential('fingerprint');

        return new ProxmoxClient(
            ProxmoxClient::normalise($integration->credential('url')) ?? '',
            ProxmoxClient::token($integration->credential('token')) ?? '',
            $fingerprint === '' ? null : ProxmoxClient::fingerprint($fingerprint),
        );
    }

    /** @return list<array<string, mixed>> every VM and container the token can see, templates too */
    private function guests(ProxmoxClient $client): array
    {
        return array_values(array_filter((array) $client->read('cluster/resources', ['type' => 'vm']), 'is_array'));
    }

    /** @return array<string, mixed>|null */
    private function guest(ProxmoxClient $client, string $id): ?array
    {
        foreach ($this->guests($client) as $guest) {
            if ((string) ($guest['vmid'] ?? '') === $id) {
                return $guest;
            }
        }

        return null;
    }

    /**
     * The client, node and listing of a VM ServerOS may change.
     *
     * @return array{0: ProxmoxClient, 1: string, 2: array<string, mixed>}
     */
    private function locate(Integration $integration, string $id): array
    {
        $client = $this->client($integration);
        $guest = ctype_digit($id) ? $this->guest($client, $id) : null;
        if ($guest === null) {
            throw new IntegrationFailed(self::t('That VM isn’t in Proxmox any more.'), IntegrationFailed::NOT_FOUND);
        }
        if (($guest['type'] ?? null) !== 'qemu') {
            throw new IntegrationFailed(self::t('ServerOS doesn’t change containers. Use Proxmox for that.'));
        }

        return [$client, (string) $guest['node'], $guest];
    }

    private function power(Integration $integration, string $id, string $what): ?string
    {
        [$client, $node] = $this->locate($integration, $id);

        return $client->task('POST', "nodes/{$node}/qemu/{$id}/status/{$what}");
    }

    // Waits on a short task (a stop, a disk resize) before the next call, which Proxmox would refuse while it holds the lock.
    private function waitFor(ProxmoxClient $client, ?string $upid, int $seconds): void
    {
        if ($upid === null) {
            return;
        }
        $node = ProxmoxClient::nodeOfTask($upid);
        for ($waited = 0; $node !== null && $waited <= $seconds; $waited++) {
            $state = self::taskState((array) $client->read("nodes/{$node}/tasks/".rawurlencode($upid).'/status'));
            if ($state->state === ActionState::FAILED) {
                throw new IntegrationFailed(self::t('Proxmox said: :message', ['message' => (string) $state->error]));
            }
            if ($state->state === ActionState::DONE) {
                return;
            }
            Sleep::for(1)->second();
        }
        throw new IntegrationFailed(self::t('Proxmox is taking too long. Check the task log in Proxmox.'), IntegrationFailed::UNAVAILABLE);
    }

    /** @param  array<string, mixed>  $task */
    private static function taskState(array $task): ActionState
    {
        if (($task['status'] ?? null) !== 'stopped') {
            return new ActionState(ActionState::RUNNING);
        }
        $exit = (string) ($task['exitstatus'] ?? '');

        return $exit === 'OK' || str_starts_with($exit, 'WARNINGS')
            ? new ActionState(ActionState::DONE)
            : new ActionState(ActionState::FAILED, rtrim($exit, '.').'.');
    }

    /**
     * @param  array<string, mixed>  $guest
     */
    private function toServer(Integration $integration, ProxmoxClient $client, array $guest): Server
    {
        $vmid = (string) ($guest['vmid'] ?? '');
        $type = (string) ($guest['type'] ?? 'qemu');
        $node = (string) ($guest['node'] ?? '');
        [$ipv4, $ipv6] = ($guest['status'] ?? null) === 'running' ? $this->addresses($integration, $client, $type, $node, $vmid) : [null, null];
        $lock = is_string($guest['lock'] ?? null) ? $guest['lock'] : null;

        return new Server(
            id: $vmid,
            name: (string) (($guest['name'] ?? '') !== '' ? $guest['name'] : ($type === 'lxc' ? "CT {$vmid}" : "VM {$vmid}")),
            status: match (true) {
                in_array($lock, ['clone', 'create'], true) => 'starting',
                $lock === 'destroyed' => 'deleting',
                $lock !== null => 'other',
                ($guest['status'] ?? null) === 'running' => 'running',
                ($guest['status'] ?? null) === 'stopped' => 'off',
                default => 'other',
            },
            type: $type,
            typeLabel: $type === 'lxc' ? self::t('Container') : self::t('VM'),
            cores: (int) ($guest['maxcpu'] ?? 0),
            memoryGb: round((float) ($guest['maxmem'] ?? 0) / 1024 ** 3, 2),
            diskGb: (int) round((float) ($guest['maxdisk'] ?? 0) / 1024 ** 3),
            location: $node,
            city: null,
            country: null,
            ipv4: $ipv4,
            ipv6: $ipv6,
            architecture: 'x86',
        );
    }

    /**
     * A running guest's IPv4 and IPv6 address: from the QEMU guest agent, or
     * the container's interfaces, or the fixed address in its cloud-init.
     * IPv6 is kept as a /128 so only that address links a machine. Kept for
     * five minutes, a minute when nothing was found.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function addresses(Integration $integration, ProxmoxClient $client, string $type, string $node, string $vmid): array
    {
        $key = "integrations:{$integration->id}:proxmox:{$vmid}:addresses";
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return [$cached[0] ?? null, $cached[1] ?? null];
        }

        $found = [];
        try {
            if ($type === 'lxc') {
                foreach ((array) $client->read("nodes/{$node}/lxc/{$vmid}/interfaces") as $if) {
                    if (is_array($if)) {
                        $found[] = [(string) ($if['name'] ?? ''), array_values(array_filter([$if['inet'] ?? null, $if['inet6'] ?? null], 'is_string'))];
                    }
                }
            } else {
                $config = (array) $client->read("nodes/{$node}/qemu/{$vmid}/config");
                if (self::agentOn($config['agent'] ?? null)) {
                    try {
                        foreach ((array) (((array) $client->read("nodes/{$node}/qemu/{$vmid}/agent/network-get-interfaces"))['result'] ?? []) as $if) {
                            if (is_array($if)) {
                                $found[] = [(string) ($if['name'] ?? ''), array_values(array_filter(array_map(fn ($a) => is_array($a) ? ($a['ip-address'] ?? null) : null, (array) ($if['ip-addresses'] ?? [])), 'is_string'))];
                            }
                        }
                    } catch (IntegrationFailed $e) {
                        if ($e->refusedCredentials()) {
                            throw $e;
                        }
                    }
                }
                if (preg_match('/(?:^|,)ip=([0-9.]+)\//', (string) ($config['ipconfig0'] ?? ''), $m) === 1) {
                    $found[] = ['cloud-init', [$m[1]]];
                }
                if (preg_match('/(?:^|,)ip6=([0-9a-f:]+)\//i', (string) ($config['ipconfig0'] ?? ''), $m) === 1) {
                    $found[] = ['cloud-init', [$m[1]]];
                }
            }
        } catch (IntegrationFailed $e) {
            if ($e->refusedCredentials()) {
                throw $e;
            }
        }

        $ipv4 = null;
        $ipv6 = null;
        foreach ($found as [$name, $ips]) {
            if (preg_match(self::SKIP_INTERFACES, $name) === 1) {
                continue;
            }
            foreach ($ips as $ip) {
                $ip = explode('/', $ip)[0];
                if ($ipv4 === null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && ! str_starts_with($ip, '127.') && ! str_starts_with($ip, '169.254.')) {
                    $ipv4 = $ip;
                }
                if ($ipv6 === null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && $ip !== '::1' && ! str_starts_with(strtolower($ip), 'fe80')) {
                    $ipv6 = $ip.'/128';
                }
            }
        }

        Cache::put($key, [$ipv4, $ipv6], $ipv4 === null && $ipv6 === null ? now()->addMinute() : now()->addMinutes(5));

        return [$ipv4, $ipv6];
    }

    /**
     * Cores for a VM, keeping its sockets when the count divides evenly.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, int>
     */
    private function cpuChange(array $config, int $cores): array
    {
        if ($cores === self::vcpus($config)) {
            return [];
        }
        $sockets = max(1, (int) ($config['sockets'] ?? 1));

        return match (true) {
            $sockets === 1 => ['cores' => $cores],
            $cores % $sockets === 0 => ['cores' => intdiv($cores, $sockets)],
            default => ['cores' => $cores, 'sockets' => 1],
        };
    }

    /** @param  array<string, mixed>  $config */
    private static function vcpus(array $config): int
    {
        return max(1, (int) ($config['cores'] ?? 1)) * max(1, (int) ($config['sockets'] ?? 1));
    }

    /** @param  array<string, mixed>  $config */
    private static function memoryMb(array $config): int
    {
        $memory = (string) ($config['memory'] ?? '512');

        return preg_match('/(?:^|current=)(\d+)/', $memory, $m) === 1 ? (int) $m[1] : 512;
    }

    /**
     * The disk it boots from and its size in GB: the first disk in its boot
     * order that isn't a CD drive or a cloud-init drive.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: int}|null
     */
    private static function bootDisk(array $config): ?array
    {
        $order = preg_match('/(?:^|,)order=([^,]+)/', (string) ($config['boot'] ?? ''), $m) === 1 ? explode(';', $m[1]) : [];
        if (is_string($config['bootdisk'] ?? null)) {
            $order[] = $config['bootdisk'];
        }
        $disks = array_values(array_filter(array_keys($config), fn (string $key) => preg_match('/^(?:scsi|virtio|sata|ide)\d+$/', $key) === 1));
        sort($disks, SORT_NATURAL);

        foreach (array_unique([...$order, ...$disks]) as $key) {
            $value = $config[$key] ?? null;
            if (! is_string($value) || str_contains($value, 'media=cdrom') || str_contains($value, 'cloudinit')) {
                continue;
            }
            if (preg_match('/(?:^|,)size=(\d+(?:\.\d+)?)([KMGT]?)/', $value, $s) === 1) {
                $gb = (float) $s[1] * match ($s[2]) {
                    'T' => 1024,
                    'M' => 1 / 1024,
                    'K' => 1 / 1024 ** 2,
                    default => 1,
                };

                return [$key, (int) round($gb)];
            }
        }

        return null;
    }

    private static function agentOn(mixed $value): bool
    {
        $enabled = null;
        foreach (explode(',', (string) $value) as $part) {
            if (! str_contains($part, '=')) {
                $enabled = $part;
            } elseif (str_starts_with($part, 'enabled=')) {
                $enabled = substr($part, 8);
            }
        }

        return in_array($enabled, ['1', 'yes', 'on', 'true'], true);
    }

    /** @param  array<string, mixed>  $config */
    private static function hasCloudInit(array $config): bool
    {
        foreach ($config as $key => $value) {
            if (preg_match('/^(?:scsi|virtio|sata|ide)\d+$/', $key) === 1 && is_string($value) && str_contains($value, 'cloudinit')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A snapshot name Proxmox takes (a letter, then letters, digits, - and _,
     * up to 40) from what the person typed, not one the VM has already.
     *
     * @param  list<string>  $taken
     */
    private static function snapName(string $name, array $taken): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9_]+/', '-', strtolower($name)), '-_');
        $slug = preg_match('/^[a-z]/', $slug) === 1 ? $slug : 's-'.$slug;
        $slug = substr(rtrim($slug, '-'), 0, 34);
        $slug = strlen($slug) < 2 ? 'snapshot' : $slug;
        $candidate = $slug;
        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = "{$slug}-{$n}";
        }

        return $candidate;
    }

    /**
     * The token's privileges that ServerOS uses and it doesn't have, from its
     * effective permissions. Running the install needs VM.Monitor before
     * Proxmox VE 9 and VM.GuestAgent.Unrestricted from 9 on.
     *
     * @return list<string>
     */
    private function missingPrivileges(ProxmoxClient $client, string $version): array
    {
        try {
            $permissions = (array) $client->read('access/permissions');
        } catch (IntegrationFailed $e) {
            if ($e->refusedCredentials()) {
                throw $e;
            }

            return [];
        }
        $has = [];
        foreach ($permissions as $privileges) {
            foreach ((array) $privileges as $privilege => $on) {
                if ((int) $on === 1) {
                    $has[(string) $privilege] = true;
                }
            }
        }
        $agent = version_compare($version, '9.0', '>=') ? 'VM.GuestAgent.Unrestricted' : 'VM.Monitor';

        return array_values(array_filter([...self::PRIVILEGES, $agent], fn (string $privilege) => ! isset($has[$privilege])));
    }
}
