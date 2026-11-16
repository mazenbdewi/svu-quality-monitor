<?php

namespace App\Console\Commands;

use App\Models\SloProposal;
use App\Services\SloProposalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

/** Read-only deployment diagnostic; never repairs or reclassifies research data. */
class AuditFrozenSloProposals extends Command
{
    protected $signature = 'slo:audit-frozen';

    protected $description = 'Verify stored SLO proposal payload digests and frozen fields without changing data.';

    public function handle(): int
    {
        try {
            if (! Schema::hasTable('slo_proposals')) {
                $this->line('slo_proposals: absent; no proposal digests to audit.');

                return self::SUCCESS;
            }
        } catch (Throwable) {
            $this->error('Frozen SLO audit incomplete: table availability could not be verified.');

            return self::FAILURE;
        }

        $verified = 0;
        $mismatchCount = 0;
        $unverifiableCount = 0;
        $mismatchIds = [];
        $unverifiableIds = [];
        try {
            DB::transaction(function () use (&$verified, &$mismatchCount, &$unverifiableCount, &$mismatchIds, &$unverifiableIds): void {
                SloProposal::query()->chunkById(250, function ($proposals) use (&$verified, &$mismatchCount, &$unverifiableCount, &$mismatchIds, &$unverifiableIds): void {
                    foreach ($proposals as $proposal) {
                        try {
                            SloProposalService::assertIntegrity($proposal);
                            $verified++;
                        } catch (LogicException) {
                            $mismatchCount++;
                            if (count($mismatchIds) < 50) {
                                $mismatchIds[] = $proposal->id;
                            }
                        } catch (Throwable) {
                            $unverifiableCount++;
                            if (count($unverifiableIds) < 50) {
                                $unverifiableIds[] = $proposal->id;
                            }
                        }
                    }
                });
            });
        } catch (Throwable) {
            $this->error('Frozen SLO audit incomplete: proposal rows could not be read.');

            return self::FAILURE;
        }

        $checked = $verified + $mismatchCount + $unverifiableCount;
        $this->line("Frozen SLO proposals checked: {$checked}; verified: {$verified}; mismatches: {$mismatchCount}; unverifiable: {$unverifiableCount}.");
        if ($mismatchCount > 0) {
            $this->error('Digest/field mismatch; forensic review required for proposal IDs: '.implode(', ', $mismatchIds).($mismatchCount > 50 ? ' (first 50 shown)' : ''));
        }
        if ($unverifiableCount > 0) {
            $this->error('Integrity could not be verified for proposal IDs: '.implode(', ', $unverifiableIds).($unverifiableCount > 50 ? ' (first 50 shown)' : ''));
        }

        if ($mismatchCount > 0 || $unverifiableCount > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
