<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Wrong passwords in a row for a username; after 5 it is locked for 5 minutes. */
class LoginLock extends Model
{
    protected $primaryKey = 'username';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = ['username', 'failures', 'locked_until'];
    protected $casts = ['locked_until' => 'datetime'];
}
