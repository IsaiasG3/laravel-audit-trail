<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Wallet;
use Tests\TestCase;

it('permite al propietario modificar su wallet', function (): void {
    /** @var TestCase $this */
    /** @var User $user */
    $user = User::factory()->create();

    $wallet = Wallet::factory()->create([
        'user_id' => $user->id,
        'balance' => 100,
    ]);

    $this->actingAs(
        $user,
        'sanctum'
    )
        ->putJson(
            "/api/wallets/{$wallet->id}",
            [
                'balance' => 500,
            ]
        )
        ->assertOk();

    expect($wallet->fresh()->balance)
        ->toBe('500.00');
});

it('impide modificar la wallet de otro usuario', function (): void {
    /** @var TestCase $this */
    /** @var User $owner */
    $owner = User::factory()->create();

    /** @var User $attacker */
    $attacker = User::factory()->create();

    $wallet = Wallet::factory()->create([
        'user_id' => $owner->id,
        'balance' => 100,
    ]);

    $this->actingAs(
        $attacker,
        'sanctum'
    )
        ->putJson(
            "/api/wallets/{$wallet->id}",
            [
                'balance' => 999999,
            ]
        )
        ->assertForbidden();

    expect($wallet->fresh()->balance)
        ->toBe('100.00');
});