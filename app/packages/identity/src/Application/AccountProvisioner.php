<?php
namespace App\Modules\Identity\Application;
use App\Modules\Identity\Domain\User;
use Illuminate\Validation\ValidationException;
class AccountProvisioner implements \App\Contracts\Module\AccountProvisioner
{
    public function createOwner(array $data): void
    {
        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => 'Diese Login-E-Mail ist bereits vergeben.']);
        }
        User::create([...$data, 'role' => 'restaurant_admin']);
    }
}
