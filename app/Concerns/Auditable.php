<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Auditable
{
    /**
     * @return array<int, string>
     */
    public function auditExcluded(): array
    {
        if (! property_exists($this, 'auditExcluded')) {
            return [];
        }

        return $this->auditExcluded;
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(
            AuditLog::class,
            'auditable'
        );
    }
    
}