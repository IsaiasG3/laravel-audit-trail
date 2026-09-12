<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\AuditChange;
use App\Services\AuditChainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class StoreAuditLogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly AuditChange $change
    ) {
        $this->afterCommit();
    }

    public function handle(
        AuditChainService $chain
    ): void {
        $chain->appendToChain(
            $this->change->toArray()
        );
    }

    public function failed(\Throwable $exception): void
    {
        logger()->error(
            'No se pudo persistir el AuditLog',
            [
                'change' => $this->change->toArray(),
                'error' => $exception->getMessage(),
            ]
        );
    }
}