<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'id', 'name', 'email', 'password', 'role', 'avatar'
    ];
    protected $hidden = [
        'password'
    ];

    // Relasi: user punya banyak tiket
    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}
