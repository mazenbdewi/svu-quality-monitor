<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstitutionSetting extends Model
{
    protected $fillable = ['institution_name', 'institution_logo'];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['institution_name' => config('app.name')]);
    }
}
