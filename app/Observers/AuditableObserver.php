<?php

declare(strict_types=1);

namespace App\Observers;

use App\DTOs\AuditChange;
use App\Jobs\StoreAuditLogJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class AuditableObserver
{
    public function created(Model $model): void
    {
        $change = AuditChange::forCreated(
            $model,
            Auth::id(),
            $this->requestContext()
        );

        $this->dispatch($change);
    }

    public function updated(Model $model): void
    {
        if ($model->getChanges() === []) {
            return;
        }

        $change = AuditChange::forUpdated(
            $model,
            Auth::id(),
            $this->requestContext()
        );

        $this->dispatch($change);
    }

    public function deleted(Model $model): void
    {
        $change = AuditChange::forDeleted(
            $model,
            Auth::id(),
            $this->requestContext()
        );

        $this->dispatch($change);
    }

    private function dispatch(AuditChange $change): void
    {
            StoreAuditLogJob::dispatch($change)
            ->onQueue('audits');
    }

    /**
     * @return array{
     *     ip: ?string,
     *     agent: ?string,
     *     url: ?string
     * }
     */
    private function requestContext(): array
    {
        if (app()->runningInConsole()) {
            return [
                'ip' => null,
                'agent' => null,
                'url' => null,
            ];
        }

        return [
            'ip' => request()->ip(),
            'agent' => request()->userAgent(),
            'url' => request()->url(),
        ];
    }
}