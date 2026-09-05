<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = ['actor_id', 'event', 'auditable_type', 'auditable_id', 'description', 'before', 'after', 'context', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'context' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
