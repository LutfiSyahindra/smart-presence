<?php

namespace App\ValueObjects;

final readonly class VerifiedAttendanceLocation
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public float $accuracy,
        public int $sampleCount,
    ) {}
}
