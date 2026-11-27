<?php

namespace App\Support\Integrations\Servers;

/**
 * A server to make. Providers with fixed types use type and keys (SSH key
 * ids kept at the provider); providers that size by hand (SizesFreely) use
 * size, and the cloud-init settings: the user to make, SSH public keys, and
 * a fixed IPv4 address with its gateway (null for DHCP).
 */
final readonly class NewServer
{
    /**
     * @param  list<string>  $keys  SSH key ids
     * @param  list<string>  $publicKeys  SSH public keys, one per item
     */
    public function __construct(
        public string $name,
        public string $location,
        public string $type,
        public string $image,
        public array $keys = [],
        public ?ServerSize $size = null,
        public ?string $user = null,
        public array $publicKeys = [],
        public ?string $address = null,
        public ?string $gateway = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'location' => $this->location,
            'type' => $this->type,
            'image' => $this->image,
            'keys' => $this->keys,
            'size' => $this->size?->toArray(),
            'user' => $this->user,
            'public_keys' => $this->publicKeys,
            'address' => $this->address,
            'gateway' => $this->gateway,
        ], fn ($value) => $value !== null && $value !== []);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            location: (string) ($data['location'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            image: (string) ($data['image'] ?? ''),
            keys: array_values(array_map('strval', (array) ($data['keys'] ?? []))),
            size: is_array($data['size'] ?? null) ? ServerSize::fromArray($data['size']) : null,
            user: isset($data['user']) ? (string) $data['user'] : null,
            publicKeys: array_values(array_map('strval', (array) ($data['public_keys'] ?? []))),
            address: isset($data['address']) ? (string) $data['address'] : null,
            gateway: isset($data['gateway']) ? (string) $data['gateway'] : null,
        );
    }
}
