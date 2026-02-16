<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UploadLink extends Model
{
    protected $fillable = [
        'user_id',
        'guest_user_id',
        'name',
        'token',
        'max_uses',
        'use_count',
        'expires_at',
        'active',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'max_uses' => 'integer',
        'use_count' => 'integer',
        'active' => 'boolean',
    ];

    protected $hidden = [
        'token',
        'guest_user_id',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function guestUser()
    {
        return $this->belongsTo(User::class, 'guest_user_id');
    }

    public function isExpired()
    {
        return $this->expires_at && now()->gt($this->expires_at);
    }

    public function isActive()
    {
        return $this->active && !$this->isExpired() && !$this->hasReachedUseLimit();
    }

    public function hasReachedUseLimit()
    {
        if ($this->max_uses === 0) {
            return false;
        }
        return $this->use_count >= $this->max_uses;
    }

    public function incrementUseCount()
    {
        $this->use_count = ($this->use_count ?? 0) + 1;
        $this->save();
    }
}
