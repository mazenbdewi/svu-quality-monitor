<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHealthAlertState extends Model
{
    protected $fillable = ['component', 'status', 'opened_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
