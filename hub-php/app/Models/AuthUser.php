<?php

namespace App\Models;

use App\Auth\HubAccounts;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * A hub account in `auth_user`, only so Sanctum can issue Coilbox's access
 * tokens to it. {@see HubAccounts} holds the account rules.
 */
class AuthUser extends Model
{
    use HasApiTokens;

    protected $table = 'auth_user';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;
}
