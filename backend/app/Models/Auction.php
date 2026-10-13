<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Auction extends Model
{
    protected $fillable = [
        'title', 'description', 'starting_price', 'reserve_price', 'current_price',
        'minimum_bid_increment', 'increment_rules', 'anti_sniping_seconds', 'extension_seconds',
        'max_extensions', 'extensions_count', 'starts_at', 'ends_at', 'status',
        'leading_bidder_id', 'winner_id', 'result',
    ];

    protected function casts(): array
    {
        return [
            'starting_price' => 'decimal:2', 'reserve_price' => 'decimal:2',
            'current_price' => 'decimal:2', 'minimum_bid_increment' => 'decimal:2',
            'increment_rules' => 'array',
            'starts_at' => 'datetime', 'ends_at' => 'datetime',
        ];
    }

    public function leadingBidder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leading_bidder_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function proxyBids(): HasMany
    {
        return $this->hasMany(ProxyBid::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AuctionEvent::class);
    }
}
