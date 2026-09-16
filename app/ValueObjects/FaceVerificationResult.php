<?php

namespace App\ValueObjects;

final readonly class FaceVerificationResult
{
    public function __construct(
        public bool $matched,
        public ?float $similarity,
        public string $provider,
    ) {}
}
