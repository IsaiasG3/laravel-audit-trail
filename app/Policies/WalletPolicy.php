<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Wallet;

final class WalletPolicy
{
    public function update(User $user, Wallet $wallet): bool
    {
        return $user->is($wallet->user);
    }

    public function viewHistory(User $user, Wallet $wallet): bool
    {
        return $user->is($wallet->user);
    }

    public function viewSnapshot(User $user, Wallet $wallet): bool
    {
        return $user->is($wallet->user);
    }

    public function viewDiff(User $user, Wallet $wallet): bool
    {
        return $user->is($wallet->user);
    }
}