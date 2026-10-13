<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bid extends Model
{
    protected $fillable = ['auction_id', 'user_id', 'amount', 'maximum_amount', 'type', 'idempotency_key'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'maximum_amount' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
