<?php

use App\Models\Auction;
use App\Services\AuctionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    app(AuctionService::class)->startDueAuctions();
    Auction::where('status', 'live')->where('ends_at', '<=', now())->each(fn (Auction $auction) => app(AuctionService::class)->close($auction));
})->everyMinute();
