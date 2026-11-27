<?php

namespace App\Support\Integrations;

use App\Models\Integration;
use App\Support\Integrations\Servers\ManagesServers;

/**
 * A service a workspace can connect an account of. See docs/integrations.md.
 */
abstract class Provider
{
    /** The IntegrationCatalogue key this provider makes real, like "hetzner". */
    abstract public function key(): string;

    abstract public function name(): string;

    /** @return list<ConnectField> */
    abstract public function fields(): array;

    /**
     * Checks the credentials against the provider's real API.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws IntegrationFailed
     */
    abstract public function verify(array $credentials): ConnectedAccount;

    /** @return list<Capability> */
    abstract public function capabilities(): array;

    public function multipleAccounts(): bool
    {
        return true;
    }

    /** The workspace permission that connects, manages and disconnects its accounts. */
    public function ability(): string
    {
        return WorkspaceIntegrations::MACHINES;
    }

    /** One line about the account for the Connected list, from its meta. */
    public function summary(Integration $integration): string
    {
        return $integration->label;
    }

    /** @return list<array{label: string, value: string}> */
    public function facts(Integration $integration): array
    {
        return [];
    }

    /** @return list<string> what the account is used for in this workspace, for its page */
    public function usage(Integration $integration): array
    {
        return [];
    }

    /** @return list<string> what stops working when it's disconnected */
    public function dependents(Integration $integration): array
    {
        return [];
    }

    /**
     * Sets up what ServerOS keeps in step with the account, once it's
     * connected and each time it's checked and works.
     */
    public function connected(Integration $integration): void {}

    /**
     * Takes back what ServerOS set up at the provider for this account, just
     * before it's disconnected, and says so (null when there was nothing).
     */
    public function disconnect(Integration $integration): ?string
    {
        return null;
    }

    /**
     * What else disconnecting can take back, ticked in its dialog (each needs the typed confirm).
     *
     * @return list<array{key: string, label: string, detail: string}>
     */
    public function disconnectChoices(Integration $integration): array
    {
        return [];
    }

    /**
     * Like disconnect(), with the disconnectChoices() keys that were ticked.
     *
     * @param  list<string>  $chosen
     */
    public function disconnectWith(Integration $integration, array $chosen): ?string
    {
        return $this->disconnect($integration);
    }

    /** Another provider whose connected accounts' tokens this one can use instead of a pasted one (R2 uses Cloudflare's). */
    public function reusesTokenOf(): ?string
    {
        return null;
    }

    /**
     * The credentials this provider takes from an account of reusesTokenOf().
     *
     * @return array<string, string>
     */
    public function reusedCredentials(Integration $other): array
    {
        return [];
    }

    /**
     * Whether this panel can connect it at all (a Discord app set up by
     * whoever runs it). While it can't, its catalogue row stays as the panel
     * has it, with unavailableNote() on the row.
     */
    public function available(): bool
    {
        return true;
    }

    public function unavailableNote(): ?string
    {
        return null;
    }

    /**
     * A workspace path that starts the provider's own connect flow (an OAuth
     * install) instead of the connect form, like "/integrations/discord/install".
     */
    public function connectPath(): ?string
    {
        return null;
    }

    public function can(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /** What a capability does with this provider, when that differs from the usual words. */
    public function describe(Capability $capability): string
    {
        return $capability->description();
    }

    public function managesServers(): bool
    {
        return $this instanceof ManagesServers;
    }

    /** @return array{key: string, name: string, fields: list<array<string, mixed>>, capabilities: list<array{key: string, label: string, description: string}>, multiple: bool} */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'name' => $this->name(),
            'fields' => array_map(fn (ConnectField $field) => $field->toArray(), $this->fields()),
            'capabilities' => array_map(fn (Capability $capability) => [...$capability->toArray(), 'description' => $this->describe($capability)], $this->capabilities()),
            'multiple' => $this->multipleAccounts(),
        ];
    }

    /** @param  array<string, string|int>  $replace */
    protected static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
