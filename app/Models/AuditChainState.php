<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditChainState extends Model
{
    protected $table = 'audit_chain_state';

    public const UPDATED_AT = 'updated_at';
    public const CREATED_AT = null;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $fillable = [
        'last_hash',
        'last_audit_log_id',
    ];

    protected $casts = [
        'last_audit_log_id' => 'integer',
        'updated_at' => 'datetime',
    ];
}