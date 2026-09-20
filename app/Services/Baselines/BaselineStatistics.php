<?php

namespace App\Services\Baselines;

/** Pure calculation from frozen membership. No live queries or Phase II logic. */
class BaselineStatistics
{
    public function calculate(string $type, array $samples, array $buckets): array
    {
        if (! in_array($type, ['i_chart', 'mr_chart', 'p_chart'], true)) {
            throw new \InvalidArgumentException('Unsupported Phase I method.');
        }
        $included = array_values(array_filter($samples, fn ($s) => $s['included']));
        $values = array_map(fn ($s) => (float) $s['measurement']['response_time_ms'], $included);
        $n = count($included);
        $ranges = [];
        for ($i = 1; $i < $n; $i++) {
            $ranges[] = abs($values[$i] - $values[$i - 1]);
        }
        $mean = $n ? array_sum($values) / $n : null;
        $mr = count($ranges) ? array_sum($ranges) / count($ranges) : null;
        $points = [];
        if ($type === 'p_chart') {
            $d = array_sum(array_map(fn ($s) => (int) $s['measurement']['problematic'], $included));
            $p = $n ? $d / $n : null;
            $cursor = 0;
            foreach ($buckets as $bucket) {
                $subgroupN = 0;
                $subgroupD = 0;
                while ($cursor < $n && $included[$cursor]['measurement']['checked_at'] < $bucket['bucket_end']) {
                    if ($included[$cursor]['measurement']['checked_at'] >= $bucket['bucket_start']) {
                        $subgroupN++;
                        $subgroupD += (int) $included[$cursor]['measurement']['problematic'];
                    }
                    $cursor++;
                }
                if ($subgroupN === 0) {
                    continue;
                }
                $sigma = sqrt($p * (1 - $p) / $subgroupN);
                $points[] = $this->point($bucket['bucket_start'], $subgroupD / $subgroupN, $p, min(1, $p + 3 * $sigma), max(0, $p - 3 * $sigma)) + ['sample_size' => $subgroupN];
            }
            $parameters = ['total_observed' => $n, 'total_problematic' => $d, 'p0' => $p, 'subgroup_count' => count($points)];
            $distribution = array_column($points, 'value');
        } elseif ($type === 'i_chart') {
            $sigma = $mr === null ? null : $mr / 1.128;
            $ucl = $sigma === null ? null : $mean + 3 * $sigma;
            $lcl = $sigma === null ? null : max(0, $mean - 3 * $sigma);
            $parameters = ['n' => $n, 'mean' => $mean, 'mr_bar' => $mr, 'sigma_estimate' => $sigma, 'cl' => $mean, 'ucl' => $ucl, 'lcl' => $lcl];
            foreach ($included as $sample) {
                $points[] = $this->point($sample['measurement']['checked_at'], (float) $sample['measurement']['response_time_ms'], $mean, $ucl, $lcl) + ['check_id' => $sample['service_check_id']];
            }
            $distribution = $values;
        } else {
            $ucl = $mr === null ? null : 3.267 * $mr;
            $lcl = $mr === null ? null : 0.0;
            $parameters = ['pair_count' => count($ranges), 'mr_bar' => $mr, 'cl' => $mr, 'ucl' => $ucl, 'lcl' => $lcl];
            foreach ($ranges as $i => $range) {
                $points[] = $this->point($included[$i + 1]['measurement']['checked_at'], $range, $mr, $ucl, $lcl) + ['check_id' => $included[$i + 1]['service_check_id'], 'previous_check_id' => $included[$i]['service_check_id']];
            }
            $distribution = $ranges;
        }

        return ['parameters' => $parameters, 'points' => $points, 'distribution' => $this->distribution($distribution)];
    }

    private function point(string $at, float $value, ?float $cl, ?float $ucl, ?float $lcl): array
    {
        return ['point_time' => $at, 'value' => $value, 'cl' => $cl, 'ucl' => $ucl, 'lcl' => $lcl,
            'signal' => ($ucl !== null && $value > $ucl) || ($lcl !== null && $value < $lcl)];
    }

    private function distribution(array $values): array
    {
        sort($values);
        $n = count($values);
        $mean = $n ? array_sum($values) / $n : null;

        return ['n' => $n, 'min' => $n ? min($values) : null, 'max' => $n ? max($values) : null,
            'mean' => $mean, 'median' => $n ? ($values[(int) floor(($n - 1) / 2)] + $values[(int) floor($n / 2)]) / 2 : null,
            'sample_sd' => $n > 1 ? sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1)) : null];
    }
}
