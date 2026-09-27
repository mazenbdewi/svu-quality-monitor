<?php

namespace App\Services;

use App\Models\ControlChart;
use App\Models\ControlChartBaseline;
use App\Models\ControlChartPoint;
use App\Models\ReliabilityMetric;
use App\Models\SlaMetric;
use Carbon\Carbon;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class InstitutionTimezone
{
    public const DEFAULT = 'Asia/Damascus';

    public static function identifiers(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    public function name(): string
    {
        // No process-wide cache: queue workers and each scheduler invocation see committed settings.
        // Missing schema is allowed during installation; connection failures must not silently switch calendars.
        if (! Schema::hasColumn('institution_settings', 'timezone')) {
            return self::DEFAULT;
        }
        $name = DB::table('institution_settings')->orderBy('id')->value('timezone') ?? self::DEFAULT;
        if (! in_array($name, self::identifiers(), true)) {
            throw new InvalidArgumentException('Invalid institution timezone.');
        }

        return $name;
    }

    public function local(DateTimeInterface|string $value, ?string $timezone = null): Carbon
    {
        $date = $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value, 'UTC');

        return $date->copy()->setTimezone($timezone ?? $this->name());
    }

    public function forRecord(?Model $record): string
    {
        if ($record instanceof ControlChartPoint) {
            return $record->controlChart?->analysis_timezone ?? 'UTC';
        }
        if ($record instanceof ControlChart || $record instanceof ControlChartBaseline || $record instanceof ReliabilityMetric || $record instanceof SlaMetric) {
            return $record->analysis_timezone ?? 'UTC';
        }

        return $this->name();
    }

    public function dayStart(string $date): Carbon
    {
        return Carbon::parse($date, $this->name())->setTimezone($this->name())->startOfDay()->utc();
    }

    public function dayEnd(string $date): Carbon
    {
        return Carbon::parse($date, $this->name())->setTimezone($this->name())->startOfDay()->addDay()->utc();
    }
}
