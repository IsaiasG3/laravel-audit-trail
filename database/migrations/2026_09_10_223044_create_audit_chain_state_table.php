<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_chain_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();

            $table->char('last_hash', 64);

            $table->unsignedBigInteger('last_audit_log_id')->nullable();

            $table->timestamp('updated_at', 6)->useCurrent();
        });

        $genesisHash = hash(
            'sha256',
            'audit-genesis-' . config('app.name')
        );

        DB::table('audit_chain_state')->insert([
            'id' => 1,
            'last_hash' => $genesisHash,
            'last_audit_log_id' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_state');
    }
};