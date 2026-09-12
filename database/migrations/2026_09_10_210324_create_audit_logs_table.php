<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

          
            $table->uuid('event_uuid')->unique();

         
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');

        
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('event', 20);

      
            $table->timestamp('occurred_at', 6);

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

        
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 2048)->nullable();
            $table->string('url', 2048)->nullable();

          
            $table->char('previous_hash', 64);
            $table->char('hash', 64);

        
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(
                ['auditable_type', 'auditable_id', 'occurred_at'],
                'audit_logs_auditable_history_index'
            );

            $table->index(
                ['user_id', 'occurred_at'],
                'audit_logs_user_history_index'
            );

            $table->index(
                ['event', 'occurred_at'],
                'audit_logs_event_history_index'
            );

            $table->index(
                ['created_at'],
                'audit_logs_created_at_index'
            );

            $table->index(
                ['previous_hash'],
                'audit_logs_previous_hash_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};