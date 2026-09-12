<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Wallet;
use Tests\TestCase;

it(
    'devuelve el diff correcto entre dos puntos en el tiempo vía API',
    function (): void {
        /** @var TestCase $this */

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Wallet $wallet */
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100,
        ]);

        $this->travelTo(
            now()->addMinute()
        );

        $wallet->update([
            'balance' => 200,
        ]);

        $checkpointA = now()->toIso8601String();

        $this->travelTo(
            now()->addMinute()
        );

        $wallet->update([
            'balance' => 300,
        ]);

        $checkpointB = now()->toIso8601String();

   
        $query = http_build_query([
            'from' => $checkpointA,
            'to' => $checkpointB,
        ]);

        $response = $this->actingAs(
            $user,
            'sanctum'
        )->getJson(
            "/api/wallets/{$wallet->id}/diff?{$query}"
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.diff.balance.from',
                200
            )
            ->assertJsonPath(
                'data.diff.balance.to',
                300
            );

        $this->travelBack();
    }
);