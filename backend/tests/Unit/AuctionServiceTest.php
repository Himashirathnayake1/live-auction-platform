<?php

namespace Tests\Unit;

use App\Models\Auction;
use App\Services\AuctionService;
use Tests\TestCase;

class AuctionServiceTest extends TestCase
{
    public function test_dynamic_increment_rules_select_the_matching_tier(): void
    {
        $auction = new Auction([
            'minimum_bid_increment' => 2500,
            'increment_rules' => [
                ['from' => 0, 'to' => 10000, 'increment' => 100],
                ['from' => 10001, 'to' => 50000, 'increment' => 500],
                ['from' => 50001, 'to' => null, 'increment' => 2500],
            ],
        ]);

        $service = new AuctionService;

        self::assertSame(100.0, $service->incrementFor($auction, 5000));
        self::assertSame(500.0, $service->incrementFor($auction, 25000));
        self::assertSame(2500.0, $service->incrementFor($auction, 100000));
    }
}
