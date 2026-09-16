<?php

namespace App\Contracts;

use App\ValueObjects\FaceVerificationResult;

interface FaceVerifier
{
    public function compare(string $profilePhoto, string $clockInPhoto): FaceVerificationResult;
}
