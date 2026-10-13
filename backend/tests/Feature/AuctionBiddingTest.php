<?php

namespace Tests\Feature;

use App\Exceptions\AuctionException;
use App\Models\Auction;
use App\Models\User;
use App\Services\AuctionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AuctionBiddingTest extends TestCase
{
    use RefreshDatabase;

    public function test_proxy_bidding_keeps_the_leader_maximum_private(): void
    {
        [$auction, $first, $second] = $this->liveAuction();
        $service = new AuctionService;

        $service->placeBid($auction, $first, 20000, 'first');
        $service->placeBid($auction, $second, 15000, 'second');

        $auction->refresh();
        self::assertSame(15500.0, (float) $auction->current_price);
        self::assertSame($first->id, $auction->leading_bidder_id);
        self::assertSame(20000.0, (float) $auction->proxyBids()->where('user_id', $first->id)->value('maximum_amount'));
    }

    public function test_duplicate_idempotency_key_returns_the_original_bid(): void
    {
        [$auction, $first] = $this->liveAuction();
        $service = new AuctionService;

        $original = $service->placeBid($auction, $first, 20000, 'same-request');
        $retry = $service->placeBid($auction, $first, 25000, 'same-request');

        self::assertSame($original->id, $retry->id);
        self::assertCount(1, $auction->bids()->get());
    }

    public function test_bid_after_end_is_rejected_and_close_selects_reserve_winner(): void
    {
        [$auction, $first] = $this->liveAuction();
        $service = new AuctionService;
        $service->placeBid($auction, $first, 20000, 'winner');
        $auction->update(['ends_at' => now()->subSecond()]);

        $closed = $service->close($auction);

        self::assertSame('completed', $closed->status);
        self::assertSame('sold', $closed->result);
        self::assertSame($first->id, $closed->winner_id);
        $this->expectException(AuctionException::class);
        $service->placeBid($auction->fresh(), $first, 30000, 'too-late');
    }

    public function test_proxy_maximum_can_meet_reserve_without_public_price_reaching_it(): void
    {
        [$auction, $first] = $this->liveAuction();
        $service = new AuctionService;

        $service->placeBid($auction, $first, 20000, 'reserve-proxy');
        $auction->update(['ends_at' => now()->subSecond()]);

        $closed = $service->close($auction);

        self::assertSame('sold', $closed->result);
        self::assertSame($first->id, $closed->winner_id);
        self::assertLessThan(15000, (float) $closed->current_price);
    }

    public function test_closing_recomputes_winner_from_highest_proxy_maximum(): void
    {
        [$auction, $first, $second] = $this->liveAuction();
        $service = new AuctionService;

        $service->placeBid($auction, $first, 6000, 'lower-proxy');
        $service->placeBid($auction, $second, 8000, 'higher-proxy');
        $auction->update(['ends_at' => now()->subSecond(), 'leading_bidder_id' => $first->id]);

        $closed = $service->close($auction);

        self::assertSame('sold', $closed->result);
        self::assertSame($second->id, $closed->winner_id);
    }

    public function test_completed_auction_without_result_is_repaired_on_close(): void
    {
        [$auction, $first] = $this->liveAuction();
        $service = new AuctionService;
        $service->placeBid($auction, $first, 20000, 'repair-result');
        $auction->update(['status' => 'completed', 'result' => null, 'ends_at' => now()->subSecond()]);

        $repaired = $service->close($auction);

        self::assertSame('sold', $repaired->result);
        self::assertSame($first->id, $repaired->winner_id);
    }

    public function test_anti_sniping_extends_only_within_the_configured_window(): void
    {
        [$auction, $first] = $this->liveAuction();
        $auction->update([
            'ends_at' => now()->addSeconds(30),
            'anti_sniping_seconds' => 120,
            'extension_seconds' => 120,
            'max_extensions' => 1,
        ]);
        $service = new AuctionService;

        $service->placeBid($auction, $first, 20000, 'sniping');

        self::assertSame(1, $auction->fresh()->extensions_count);
        self::assertTrue($auction->fresh()->ends_at->greaterThan(now()->addSeconds(100)));
    }

    /** @return array{0:Auction,1:User,2:User} */
    private function liveAuction(): array
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $auction = Auction::create([
            'title' => 'Test lot',
            'description' => 'Test description',
            'starting_price' => 10000,
            'reserve_price' => 15000,
            'current_price' => 10000,
            'minimum_bid_increment' => 500,
            'starts_at' => Carbon::now()->subMinute(),
            'ends_at' => Carbon::now()->addHour(),
            'status' => 'live',
        ]);

        return [$auction, $first, $second];
    }
}
