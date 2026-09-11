<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Geolocation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfficeLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('office_locations', 'name')],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'between:1,100000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama lokasi kantor wajib diisi.',
            'name.unique' => 'Nama lokasi kantor sudah digunakan.',
            'name.max' => 'Nama lokasi kantor maksimal 100 karakter.',
            'address.max' => 'Alamat maksimal 500 karakter.',
            'latitude.required' => 'Latitude wajib diisi atau dipilih melalui peta.',
            'latitude.numeric' => 'Latitude harus berupa angka.',
            'latitude.between' => 'Latitude harus berada di antara -90 dan 90.',
            'longitude.required' => 'Longitude wajib diisi atau dipilih melalui peta.',
            'longitude.numeric' => 'Longitude harus berupa angka.',
            'longitude.between' => 'Longitude harus berada di antara -180 dan 180.',
            'radius_meters.required' => 'Radius lokasi wajib diisi.',
            'radius_meters.integer' => 'Radius harus berupa bilangan bulat.',
            'radius_meters.between' => 'Radius harus berada di antara 1 dan 100.000 meter.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $address = str($this->input('address'))->squish()->toString();

        $this->merge([
            'name' => str($this->input('name'))->squish()->toString(),
            'address' => $address !== '' ? $address : null,
        ]);
    }
}
