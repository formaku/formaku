<?php

namespace Formaku\Cms\Jobs;

use Formaku\Cms\Services\PortalAcceptance;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

class RunPortalAcceptance implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 140;

    public int $uniqueFor = 160;

    public function __construct(public ?int $projectId, public string $fingerprint) {}

    public function uniqueId(): string
    {
        return $this->fingerprint;
    }

    public function handle(PortalAcceptance $checks): void
    {
        if (! $checks->available() || $checks->report($this->projectId)['fingerprint'] !== $this->fingerprint) {
            return;
        }

        Artisan::call('formaku:check-portal', $this->projectId === null ? ['--installed' => true] : ['project' => $this->projectId]);
    }
}
