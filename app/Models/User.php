<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * An account: one region (or "Ghana" for a national administrator); the support account is the superuser and has
 * no region.
 */
#[Fillable(['username', 'password', 'full_name', 'region', 'cadre', 'must_change'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_superuser' => 'boolean',
            'must_change' => 'boolean',
        ];
    }
}
