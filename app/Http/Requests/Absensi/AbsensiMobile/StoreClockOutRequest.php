<?php

namespace App\Http\Requests\Absensi\AbsensiMobile;

class StoreClockOutRequest extends StoreClockInRequest
{
    // Clock-out uses the same GPS, radius confirmation, and live-photo rules as clock-in.
}
