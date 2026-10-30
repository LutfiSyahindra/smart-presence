<?php

namespace App\Contracts;

interface ProfilePhotoValidator
{
    public function validateProfilePhoto(string $photo): void;
}
