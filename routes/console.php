<?php

use App\Models\User;
use App\Services\Accounts;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Make the support account, or give it a new password: php artisan create-support
 * Prints a new random password once; the first sign-in with it asks for a new one. With --if-missing an existing
 * support account is left as it is (server_setup.sh runs it on every update).
 */
Artisan::command('create-support {--username=admin} {--if-missing}', function () {
    $name = Accounts::key($this->option('username'));
    $user = User::where('username', $name)->first();
    if ($user && $this->option('if-missing')) {
        $this->line("Support account $name already exists; its password is unchanged.");

        return;
    }
    $pw = Accounts::tempPassword().'-'.Accounts::tempPassword();
    $user ??= new User(['username' => $name]);
    $user->forceFill(['is_superuser' => true, 'region' => '', 'must_change' => true, 'password' => $pw])->save();
    DB::table('sessions')->where('user_id', $user->id)->delete();
    $this->line("Support account\n  username: $name\n  password: $pw\n"
        .'Keep it with the support team. It is not shown again. The first sign-in asks for a new password.');
})->purpose('Create the support account, or set a new random password for it.');
