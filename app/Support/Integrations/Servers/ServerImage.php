<?php

namespace App\Support\Integrations\Servers;

/**
 * A system a new server can start from. A template that lives in one place
 * (a Proxmox VM template on its node) says where, its own size (the
 * smallest disk a copy can have), and whether it can run the install
 * itself (agent) and take cloud-init settings.
 */
final readonly class ServerImage
{
    public function __construct(
        public string $id,
        public string $label,
        public string $os,
        public ?string $version,
        public string $architecture,
        public bool $recommended = false,
        public ?string $location = null,
        public ?ServerSize $size = null,
        public ?bool $agent = null,
        public ?bool $cloudInit = null,
    ) {}

    /** @return array{id: string, label: string, os: string, version: string|null, architecture: string, recommended: bool, location?: string|null, size?: array{cores: int, memoryGb: float, diskGb: int}|null, agent?: bool|null, cloudInit?: bool|null} */
    public function toArray(): array
    {
        $out = ['id' => $this->id, 'label' => $this->label, 'os' => $this->os, 'version' => $this->version, 'architecture' => $this->architecture, 'recommended' => $this->recommended];

        return $this->location === null && $this->size === null && $this->agent === null && $this->cloudInit === null
            ? $out
            : [...$out, 'location' => $this->location, 'size' => $this->size?->toArray(), 'agent' => $this->agent, 'cloudInit' => $this->cloudInit];
    }

    /** @param  array<string, mixed>  $i */
    public static function fromArray(array $i): self
    {
        return new self(
            (string) ($i['id'] ?? ''),
            (string) ($i['label'] ?? ''),
            (string) ($i['os'] ?? ''),
            isset($i['version']) ? (string) $i['version'] : null,
            (string) ($i['architecture'] ?? 'x86'),
            (bool) ($i['recommended'] ?? false),
            isset($i['location']) ? (string) $i['location'] : null,
            is_array($i['size'] ?? null) ? ServerSize::fromArray($i['size']) : null,
            isset($i['agent']) ? (bool) $i['agent'] : null,
            isset($i['cloudInit']) ? (bool) $i['cloudInit'] : null,
        );
    }
}
