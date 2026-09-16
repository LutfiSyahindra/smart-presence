<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class FaceVerificationException extends RuntimeException
{
    public const INVALID_PROFILE_PHOTO = 'invalid_profile_photo';

    public const FACE_NOT_FOUND = 'face_not_found';

    public const MULTIPLE_FACES = 'multiple_faces';

    public const SPOOF_DETECTED = 'spoof_detected';

    public const SERVICE_UNAVAILABLE = 'service_unavailable';

    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
