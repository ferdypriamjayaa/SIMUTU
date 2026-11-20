<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An explicit per-user grant (is_granted = true) or restriction (false)
 * that takes precedence over the user's role.
 */
class UserPermission extends Model
{
    protected $fillable = ['permission', 'is_granted'];

    protected function casts(): array
    {
        return ['is_granted' => 'boolean'];
    }
}
