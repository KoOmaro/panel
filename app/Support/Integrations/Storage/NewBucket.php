<?php

namespace App\Support\Integrations\Storage;

/** A bucket about to be made: its name, how long backups stay, and where it lives. */
final readonly class NewBucket
{
    public function __construct(
        public string $name,
        public ?int $keepDays = null,
        public ?string $location = null,
        public string $jurisdiction = 'default',
        public string $workspace = '',
    ) {}

    public function named(string $name): self
    {
        return new self($name, $this->keepDays, $this->location, $this->jurisdiction, $this->workspace);
    }
}
