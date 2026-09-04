<?php

namespace App\Livewire;

use App\Services\SystemHealthService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SystemHealthIndicator extends Component
{
    /** @var array{scheduler: array<string, mixed>, queue: array<string, mixed>} */
    public array $health = [];

    public function mount(SystemHealthService $systemHealth): void
    {
        $this->refreshHealth($systemHealth);
    }

    public function refreshHealth(SystemHealthService $systemHealth): void
    {
        $snapshot = $systemHealth->snapshot();

        $this->health = [
            'scheduler' => $snapshot['scheduler'],
            'queue' => $snapshot['queue'],
        ];
    }

    public function render(): View
    {
        return view('livewire.system-health-indicator');
    }
}
