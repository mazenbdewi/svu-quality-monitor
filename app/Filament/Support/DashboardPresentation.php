<?php

namespace App\Filament\Support;

use Illuminate\Support\Collection;

/** Formats the existing snapshot without performing queries or recalculating metrics. */
class DashboardPresentation
{
    public static function attention(array $dashboard): Collection
    {
        $items = collect();
        foreach ($dashboard['services'] as $service) {
            $status = $service->executive_status;
            if (in_array($status, ['down', 'pending_failure', 'recovering', 'critical'], true)) {
                $items->push(['name' => $service->name, 'service' => $service, 'status' => $status]);
            }
            $sla = $service->getRelation('executiveSlaMetric');
            if ($sla && in_array($sla->status, ['breached', 'at_risk'], true)) {
                $items->push(['name' => $service->name, 'service' => $service, 'status' => $sla->status]);
            }
        }
        foreach ($dashboard['ssl']['expiring'] as $check) {
            if ((int) data_get($check->metadata, 'days_remaining') <= 30) {
                $items->push(['name' => $check->monitoredService?->name, 'service' => $check->monitoredService, 'status' => 'ssl_expiring']);
            }
        }
        foreach ($dashboard['spc']['services'] as $name) {
            $items->push(['name' => $name, 'service' => null, 'status' => 'out_of_control']);
        }
        $priority = array_flip(['down', 'pending_failure', 'recovering', 'breached', 'at_risk', 'ssl_expiring', 'critical', 'out_of_control']);

        return $items->sortBy(fn (array $item): int => $priority[$item['status']])->values();
    }

    public static function duration(int $seconds): string
    {
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
