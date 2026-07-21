<?php

namespace App\Exports\Reports\Sheets\Concerns;

use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

trait AppliesReportSheetFormatting
{
    /**
     * @return array<int, array<string, array<string, bool>>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => static function (AfterSheet $event): void {
                $event->sheet->getDelegate()->freezePane('A2');
            },
        ];
    }
}
