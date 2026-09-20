<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** Application write boundary; authorized lifecycle transitions use their dedicated service. */
class ImmutableResearchBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('Research snapshots cannot be updated; create a new version.');
    }

    public function delete()
    {
        throw new LogicException('Research history cannot be deleted.');
    }

    public function forceDelete()
    {
        throw new LogicException('Research history cannot be deleted.');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException('Research snapshots cannot be upserted.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Research snapshots cannot be incremented.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Research snapshots cannot be decremented.');
    }

    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        throw new LogicException('Research snapshots cannot be replaced.');
    }
}
