<?php

namespace App\Support\Integrations\Storage;

/** S3 credentials ServerOS made for one bucket, and how to delete them again. */
final readonly class BucketKey
{
    public function __construct(
        public string $id,
        public string $accessKey,
        public string $secretKey,
        public string $kind,
    ) {}
}
