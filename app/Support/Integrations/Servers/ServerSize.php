<?php

namespace App\Support\Integrations\Servers;

/**
 * A size picked by hand, for providers without fixed server types
 * (Proxmox VE): CPU cores, memory and the boot disk.
 */
final readonly class ServerSize
{
    public function __construct(
        public int $cores,
        public float $memoryGb,
        public int $diskGb,
    ) {}

    public function memoryMb(): int
    {
        return (int) round($this->memoryGb * 1024);
    }

    public function label(): string
    {
        $memory = fmod($this->memoryGb, 1.0) === 0.0 ? (string) (int) $this->memoryGb : rtrim(rtrim(number_format($this->memoryGb, 2, '.', ''), '0'), '.');

        return "{$this->cores} vCPU · {$memory} GB · {$this->diskGb} GB";
    }

    /** @return array{cores: int, memoryGb: float, diskGb: int} */
    public function toArray(): array
    {
        return ['cores' => $this->cores, 'memoryGb' => $this->memoryGb, 'diskGb' => $this->diskGb];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self((int) ($data['cores'] ?? 0), (float) ($data['memoryGb'] ?? 0), (int) ($data['diskGb'] ?? 0));
    }

    public static function of(Server $server): self
    {
        return new self($server->cores, $server->memoryGb, $server->diskGb);
    }
}
