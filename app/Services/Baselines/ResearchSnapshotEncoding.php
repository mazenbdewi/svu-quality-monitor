<?php

namespace App\Services\Baselines;

/** Canonical object key order survives JSON column normalization across database engines. */
final class ResearchSnapshotEncoding
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR);
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalize(...), $value);
    }
}
