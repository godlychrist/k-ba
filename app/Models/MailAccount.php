<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailAccount extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(MailRecord::class);
    }

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token', 'sync_cursor'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'last_synced_at' => 'datetime', 'sync_started_at' => 'datetime'];
    }
}
