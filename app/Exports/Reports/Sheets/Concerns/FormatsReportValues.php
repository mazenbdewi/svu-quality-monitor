<?php

namespace App\Exports\Reports\Sheets\Concerns;

use Carbon\Carbon;

trait FormatsReportValues
{
    protected function boolLabel(?bool $value): string
    {
        if ($value === null) {
            return '';
        }

        return $value ? __('monitoring.booleans.yes') : __('monitoring.booleans.no');
    }

    protected function dateTime(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        $date = $value instanceof Carbon ? $value : Carbon::parse($value);

        return $date->format('Y-m-d H:i:s');
    }

    protected function date(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        $date = $value instanceof Carbon ? $value : Carbon::parse($value);

        return $date->format('Y-m-d');
    }

    protected function chartTypeLabel(?string $value): string
    {
        if (! $value) {
            return '';
        }

        return __("monitoring.chart_types.{$value}");
    }

    protected function metricNameLabel(?string $value): string
    {
        if (! $value) {
            return '';
        }

        return __("monitoring.metrics.{$value}");
    }

    protected function periodTypeLabel(?string $value): string
    {
        if (! $value) {
            return '';
        }

        return __("monitoring.reliability_metrics.period_types.{$value}");
    }

    protected function incidentStatusLabel(?string $value): string
    {
        if (! $value) {
            return '';
        }

        return __("monitoring.statuses.{$value}");
    }

    protected function incidentTypeLabel(?string $value): string
    {
        if (! $value) {
            return '';
        }

        return __("monitoring.service_incidents.incident_types.{$value}");
    }

    protected function problemTypeLabel(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $translationKey = "monitoring.service_status.problem_types.{$value}";
        $translation = __($translationKey);

        return $translation === $translationKey ? $value : $translation;
    }

    protected function severityLabel(?string $value): string
    {
        if (! $value) {
            return '';
        }

        return __("monitoring.service_incidents.severities.{$value}");
    }

    protected function signalTypeLabel(?string $value): string
    {
        if (! $value) {
            return '';
        }

        return __("monitoring.control_charts.signal_types.{$value}");
    }
}
