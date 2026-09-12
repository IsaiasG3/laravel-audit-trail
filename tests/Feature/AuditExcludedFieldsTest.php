<?php

use App\Models\AuditLog;
use App\Models\User;

it('nunca incluye password ni remember_token en los logs de auditoría de User', function () {
    $user = User::factory()->create(['name' => 'Original']);

    $user->update(['name' => 'Editado', 'password' => bcrypt('nueva-clave-123')]);

    $log = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->new_values)->toHaveKey('name')
        ->and($log->new_values)->not->toHaveKey('password')
        ->and($log->old_values)->not->toHaveKey('password');
});