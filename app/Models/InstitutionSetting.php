<?php

namespace App\Models;

use App\Services\InstitutionTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class InstitutionSetting extends Model
{
    protected $fillable = ['institution_name', 'institution_logo', 'timezone'];

    protected $attributes = ['timezone' => 'Asia/Damascus'];

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            if (! in_array($setting->timezone, InstitutionTimezone::identifiers(), true)) {
                throw ValidationException::withMessages(['timezone' => __('institution_timezone.invalid')]);
            }
        });
    }

    public static function current(): self
    {
        return static::query()->orderBy('id')->firstOrCreate([], ['institution_name' => config('app.name')]);
    }
}
