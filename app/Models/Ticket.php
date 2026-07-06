<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Ticket extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'title', 'description', 'status',
        'priority', 'category', 'user_id', 'assigned_to'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    // Siapa yang buat tiket
    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Siapa yang ditugaskan
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // Komentar di tiket ini
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
}