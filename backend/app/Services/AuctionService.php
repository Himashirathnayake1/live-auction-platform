<?php

namespace App\Services;

use App\Exceptions\AuctionException;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\Bid;
use App\Models\ProxyBid;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AuctionService
{
    public function placeBid(Auction $auction, User $user, float $maximumAmount, ?string $idempotencyKey = null): Bid
    {
        return DB::transaction(function () use ($auction, $user, $maximumAmount, $idempotencyKey): Bid {
            $auction = Auction::query()->lockForUpdate()->findOrFail($auction->id);

            if ($idempotencyKey) {
                $existing = Bid::query()->where('auction_id', $auction->id)
                    ->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $now = now();
            if ($auction->status === 'scheduled' && $now->gte($auction->starts_at)) {
                $auction->update(['status' => 'live']);
            }
            if ($auction->status !== 'live' || $now->lt($auction->starts_at)) {
                throw new AuctionException('Auction is not currently live.', 409);
            }
            if ($now->gte($auction->ends_at)) {
                throw new AuctionException('Auction has already ended.', 409);
            }
            $minimum = (float) $auction->current_price + $this->incrementFor($auction, (float) $auction->current_price);
            if ($maximumAmount < $minimum) {
                throw new AuctionException(sprintf('Bid must be at least %.2f.', $minimum), 422);
            }

            $proxy = ProxyBid::query()->where('auction_id', $auction->id)
                ->where('user_id', $user->id)->lockForUpdate()->first();
            if ($proxy) {
                $proxy->update(['maximum_amount' => max((float) $proxy->maximum_amount, $maximumAmount)]);
            } else {
                $proxy = ProxyBid::create([
                    'auction_id' => $auction->id, 'user_id' => $user->id,
                    'maximum_amount' => $maximumAmount,
                ]);
            }

            $proxies = ProxyBid::query()->where('auction_id', $auction->id)
                ->orderByDesc('maximum_amount')->orderBy('created_at')->lockForUpdate()->get();
            $previousLeader = $auction->leading_bidder_id;
            [$leader, $price] = $this->resolvePrice($auction, $proxies, $previousLeader);
            $auction->update(['current_price' => $price, 'leading_bidder_id' => $leader]);

            $bid = Bid::create([
                'auction_id' => $auction->id, 'user_id' => $user->id,
                'amount' => $price, 'maximum_amount' => $maximumAmount,
                'type' => $leader === $user->id ? 'manual' : 'proxy',
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->event($auction, $user, 'BID_PLACED', ['amount' => $price]);
            $this->event($auction, $user, 'AUTO_BID_PLACED', [
                'amount' => $price,
                'maximum_amount' => null,
            ]);
            if ($previousLeader !== $leader) {
                $this->event($auction, User::find($leader), 'LEADER_CHANGED', ['user_id' => $leader]);
            }
            if ($now->diffInSeconds($auction->ends_at) <= $auction->anti_sniping_seconds
                && $now->lt($auction->ends_at)
                && $auction->extensions_count < $auction->max_extensions) {
                $auction->increment('extensions_count');
                $auction->update(['ends_at' => $auction->ends_at->copy()->addSeconds($auction->extension_seconds)]);
                $this->event($auction, $user, 'AUCTION_EXTENDED', [
                    'ends_at' => $auction->ends_at->toISOString(),
                    'extensions_count' => $auction->extensions_count,
                ]);
            }

            return $bid;
        });
    }

    public function close(Auction $auction): Auction
    {
        return DB::transaction(function () use ($auction): Auction {
            $auction = Auction::query()->lockForUpdate()->findOrFail($auction->id);
            if ($auction->status === 'cancelled'
                || ($auction->status === 'completed' && $auction->result !== null)) {
                return $auction;
            }
            if (now()->lt($auction->ends_at)) {
                throw new AuctionException('Auction has not reached its ending time.', 409);
            }
            $highestProxy = $auction->proxyBids()
                ->orderByDesc('maximum_amount')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            $highestMaximum = $highestProxy?->maximum_amount;
            $winnerId = $highestProxy?->user_id;
            $reserveMet = $auction->reserve_price === null
                || (float) $highestMaximum >= (float) $auction->reserve_price;
            $auction->update([
                'status' => 'completed',
                'result' => $reserveMet && $winnerId ? 'sold' : 'reserve_not_met',
                'winner_id' => $reserveMet ? $winnerId : null,
            ]);
            $this->event($auction, null, 'AUCTION_ENDED', ['result' => $auction->result]);
            if ($auction->winner_id) {
                $this->event($auction, $auction->winner, 'WINNER_SELECTED', ['user_id' => $auction->winner_id]);
            }

            return $auction;
        });
    }

    public function startDueAuctions(): int
    {
        $count = 0;
        Auction::query()->where('status', 'scheduled')->where('starts_at', '<=', now())
            ->each(function (Auction $auction) use (&$count): void {
                $auction->update(['status' => 'live']);
                $this->event($auction, null, 'AUCTION_STARTED');
                $count++;
            });

        return $count;
    }

    public function syncStatus(Auction $auction): Auction
    {
        if ($auction->status === 'scheduled' && now()->gte($auction->starts_at)) {
            $auction->update(['status' => 'live']);
            $this->event($auction, null, 'AUCTION_STARTED');
        }

        if ($auction->status === 'live' && now()->gte($auction->ends_at)) {
            return $this->close($auction);
        }

        return $auction->fresh(['leadingBidder:id,name', 'winner:id,name']);
    }

    public function incrementFor(Auction $auction, float $price): float
    {
        foreach ($auction->increment_rules ?: [] as $rule) {
            if ($price >= (float) $rule['from'] && ($rule['to'] === null || $price <= (float) $rule['to'])) {
                return (float) $rule['increment'];
            }
        }

        return (float) $auction->minimum_bid_increment;
    }

    public function cancel(Auction $auction): Auction
    {
        return DB::transaction(function () use ($auction): Auction {
            $auction = Auction::query()->lockForUpdate()->findOrFail($auction->id);
            if (in_array($auction->status, ['completed', 'cancelled'], true)) {
                return $auction;
            }
            $auction->update(['status' => 'cancelled', 'result' => 'cancelled']);
            $this->event($auction, null, 'AUCTION_CANCELLED');

            return $auction;
        });
    }

    public function recordRejectedBid(Auction $auction, User $user, string $reason): void
    {
        $this->event($auction, $user, 'BID_REJECTED', ['reason' => $reason]);
    }

    /** @return array{0:int,1:float} */
    private function resolvePrice(Auction $auction, $proxies, ?int $previousLeader): array
    {
        $top = $proxies->first();
        if (! $top) {
            throw new AuctionException('A valid bid is required.', 422);
        }
        $second = $proxies->get(1);
        $price = $second
            ? min((float) $top->maximum_amount, (float) $second->maximum_amount + $this->incrementFor($auction, (float) $second->maximum_amount))
            : ($previousLeader === (int) $top->user_id
                ? (float) $auction->current_price
                : max((float) $auction->starting_price, (float) $auction->current_price + $this->incrementFor($auction, (float) $auction->current_price)));

        return [(int) $top->user_id, round($price, 2)];
    }

    private function event(Auction $auction, ?User $user, string $type, array $payload = []): void
    {
        AuctionEvent::create([
            'auction_id' => $auction->id, 'user_id' => $user?->id,
            'type' => $type, 'payload' => $payload,
        ]);
    }
}
