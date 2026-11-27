<?php

namespace App\Support\Integrations\Servers;

/**
 * What a server sized by hand can be resized to, and which parts change
 * while it runs. The disk only grows.
 */
final readonly class SizeLimits
{
    public function __construct(
        public int $maxCores,
        public float $maxMemoryGb,
        public int $minDiskGb,
        public bool $liveCores = false,
        public bool $liveMemory = false,
    ) {}

    /** @return array{maxCores: int, maxMemoryGb: float, minDiskGb: int, liveCores: bool, liveMemory: bool} */
    public function toArray(): array
    {
        return [
            'maxCores' => $this->maxCores,
            'maxMemoryGb' => $this->maxMemoryGb,
            'minDiskGb' => $this->minDiskGb,
            'liveCores' => $this->liveCores,
            'liveMemory' => $this->liveMemory,
        ];
    }
}
