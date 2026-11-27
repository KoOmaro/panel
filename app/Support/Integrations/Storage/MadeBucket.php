<?php

namespace App\Support\Integrations\Storage;

/** A bucket the provider made, and where its S3 API is. */
final readonly class MadeBucket
{
    public function __construct(
        public string $name,
        public string $endpoint,
        public string $region,
        public ?string $id = null,
        public ?string $location = null,
        public ?string $jurisdiction = null,
    ) {}
}
