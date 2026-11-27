<?php

namespace App\Support\Integrations\Tailscale;

use App\Models\Integration;
use App\Models\TailscaleChange;
use App\Models\TailscaleDevice;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;

final class TailscaleProvider extends Provider
{
    public const array DEFAULT_TAGS = ['tag:serveros'];

    public const string TAG_PATTERN = '/^tag:[A-Za-z0-9][A-Za-z0-9-]{0,49}$/';

    public function key(): string
    {
        return 'tailscale';
    }

    public function name(): string
    {
        return 'Tailscale';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'token',
                label: self::t('API access token or OAuth client secret'),
                help: self::t('In Tailscale, open Settings › Keys and generate an API access token. Or make an OAuth client in Settings › OAuth clients with the devices:core and auth_keys scopes and the tags below, paste its secret here and its ID underneath.'),
                link: 'https://login.tailscale.com/admin/settings/keys',
                linkLabel: self::t('Open Tailscale keys'),
                placeholder: self::t('Paste the token'),
                rules: ['required', 'string', 'min:20', 'max:300', 'regex:/^\S+$/'],
            ),
            new ConnectField(
                key: 'tailnet',
                label: self::t('Tailnet'),
                secret: false,
                help: self::t('Keep - for the token’s own tailnet, or use its name from Settings › General.'),
                placeholder: '-',
                rules: ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._@+-]+$/'],
                value: TailscaleClient::DEFAULT_TAILNET,
            ),
            new ConnectField(
                key: 'client_id',
                label: self::t('OAuth client ID'),
                secret: false,
                help: self::t('Only for an OAuth client. Leave it empty for an access token.'),
                rules: ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-]+$/'],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::Tailnet];
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $token = (string) ($credentials['token'] ?? '');
        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        if ($clientId === '' && str_starts_with($token, 'tskey-client-')) {
            throw new IntegrationFailed(self::t('That’s an OAuth client secret. Add the client’s ID too.'), IntegrationFailed::INVALID, field: 'client_id');
        }
        $tailnet = self::tailnetOf($credentials);
        $devices = (new TailscaleClient($token, $tailnet, $clientId === '' ? null : $clientId))->devices();
        $dns = self::dnsName($devices);

        return new ConnectedAccount(
            $tailnet !== TailscaleClient::DEFAULT_TAILNET ? $tailnet : ($dns ?? 'Tailscale'),
            ['tailnet' => $tailnet, 'dns' => $dns, 'devices' => count($devices), 'oauth' => $clientId !== ''],
        );
    }

    public function summary(Integration $integration): string
    {
        $devices = (int) ($integration->meta['devices'] ?? 0);
        $machines = TailscaleDevice::query()->where('integration_id', $integration->id)->whereNotNull('machine_id')->count();

        return match (true) {
            $machines > 0 => $devices === 1 ? self::t('1 device, :machines on ServerOS', ['machines' => $machines]) : self::t(':count devices, :machines on ServerOS', ['count' => $devices, 'machines' => $machines]),
            $devices === 1 => self::t('1 device'),
            default => self::t(':count devices', ['count' => $devices]),
        };
    }

    public function facts(Integration $integration): array
    {
        $meta = $integration->meta ?? [];
        $tailnet = (string) ($meta['tailnet'] ?? TailscaleClient::DEFAULT_TAILNET);
        $facts = [['label' => self::t('Tailnet'), 'value' => $tailnet === TailscaleClient::DEFAULT_TAILNET ? self::t('The token’s own') : $tailnet]];
        if (is_string($meta['dns'] ?? null)) {
            $facts[] = ['label' => self::t('MagicDNS'), 'value' => $meta['dns']];
        }
        $facts[] = ['label' => self::t('Signs in with'), 'value' => ($meta['oauth'] ?? false) ? self::t('OAuth client') : self::t('Access token')];
        $facts[] = ['label' => self::t('Tags for new machines'), 'value' => self::tags($integration) === [] ? self::t('None') : implode(', ', self::tags($integration))];

        return $facts;
    }

    public function usage(Integration $integration): array
    {
        $machines = TailscaleDevice::query()->where('integration_id', $integration->id)->whereNotNull('machine_id')
            ->with('machine:id,name')->get()->pluck('machine.name')->filter()->sort()->values();
        $joined = TailscaleChange::query()->where('integration_id', $integration->id)->where('kind', TailscaleChange::JOIN)->where('state', 'done')->count();
        $out = [];
        $out[] = match ($machines->count()) {
            0 => self::t('No machine is on this tailnet yet. Add one from its settings or from the list here.'),
            1 => self::t(':machine is on this tailnet. Its page shows its tailnet name and address.', ['machine' => $machines->first()]),
            2, 3 => self::t(':machines are on this tailnet. Their pages show their tailnet names and addresses.', ['machines' => $machines->implode(', ')]),
            default => self::t(':count machines are on this tailnet. Their pages show their tailnet names and addresses.', ['count' => $machines->count()]),
        };
        if ($joined > 0) {
            $out[] = $joined === 1
                ? self::t('ServerOS put 1 machine on the tailnet with a one-off key.')
                : self::t('ServerOS put :count machines on the tailnet with one-off keys.', ['count' => $joined]);
        }

        return $out;
    }

    public function dependents(Integration $integration): array
    {
        $machines = TailscaleDevice::query()->where('integration_id', $integration->id)->whereNotNull('machine_id')->count();
        $active = TailscaleChange::query()->where('integration_id', $integration->id)->where('state', TailscaleChange::RUNNING)->count();
        $out = [];
        if ($machines > 0) {
            $out[] = $machines === 1
                ? self::t('1 machine stays on the tailnet, but ServerOS stops showing its address and can’t take it off.')
                : self::t(':count machines stay on the tailnet, but ServerOS stops showing their addresses and can’t take them off.', ['count' => $machines]);
        }
        if ($active > 0) {
            $out[] = $active === 1
                ? self::t('1 machine being added or taken off stops being followed.')
                : self::t(':count machines being added or taken off stop being followed.', ['count' => $active]);
        }

        return $out;
    }

    public function client(Integration $integration): TailscaleClient
    {
        $clientId = trim($integration->credential('client_id'));

        return new TailscaleClient($integration->credential('token'), self::tailnetOf($integration->credentials), $clientId === '' ? null : $clientId);
    }

    /** @return list<string> */
    public static function tags(Integration $integration): array
    {
        $tags = $integration->setting('tags');

        return is_array($tags) ? array_values(array_filter($tags, fn ($tag) => is_string($tag) && preg_match(self::TAG_PATTERN, $tag) === 1)) : self::DEFAULT_TAGS;
    }

    /** @param  array<string, string>  $credentials */
    public static function tailnetOf(array $credentials): string
    {
        $tailnet = trim((string) ($credentials['tailnet'] ?? ''));

        return $tailnet === '' ? TailscaleClient::DEFAULT_TAILNET : $tailnet;
    }

    /**
     * The tailnet's MagicDNS name, from its devices' names (web-1.tail1234.ts.net).
     *
     * @param  list<array<string, mixed>>  $devices
     */
    public static function dnsName(array $devices): ?string
    {
        foreach ($devices as $device) {
            $name = rtrim((string) ($device['name'] ?? ''), '.');
            if (($device['isExternal'] ?? false) !== true && substr_count($name, '.') >= 2) {
                return substr($name, strpos($name, '.') + 1);
            }
        }

        return null;
    }
}
