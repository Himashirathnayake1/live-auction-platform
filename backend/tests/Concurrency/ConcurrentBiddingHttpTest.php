<?php

namespace Tests\Concurrency;

use App\Models\Auction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConcurrentBiddingHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_parallel_http_bids_against_mysql_leave_one_consistent_leader(): void
    {
        $baseUrl = rtrim((string) env('CONCURRENCY_TEST_URL'), '/');
        if ($baseUrl === '' || config('database.default') !== 'mysql') {
            $this->markTestSkipped('Set CONCURRENCY_TEST_URL and use the MySQL PHPUnit configuration.');
        }
        if (! function_exists('curl_multi_init')) {
            $this->markTestSkipped('The PHP cURL extension is required.');
        }

        $users = collect(range(1, 3))->map(fn (int $number) => User::factory()->create([
            'name' => "Concurrent bidder {$number}",
            'role' => 'bidder',
        ]));
        $auction = Auction::create([
            'title' => 'HTTP concurrency test',
            'description' => 'Test auction',
            'starting_price' => 50000,
            'current_price' => 50000,
            'reserve_price' => null,
            'minimum_bid_increment' => 500,
            'anti_sniping_seconds' => 0,
            'extension_seconds' => 60,
            'max_extensions' => 3,
            'starts_at' => Carbon::now()->subMinute(),
            'ends_at' => Carbon::now()->addHour(),
            'status' => 'live',
        ]);

        $requests = $users->values()->map(function (User $user, int $index) use ($baseUrl, $auction): array {
            $token = $user->createToken('concurrency-test')->plainTextToken;
            $handle = curl_init("{$baseUrl}/api/auctions/{$auction->id}/bids");
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    "Authorization: Bearer {$token}",
                    "Idempotency-Key: concurrency-{$index}",
                ],
                CURLOPT_POSTFIELDS => json_encode(['maximum_amount' => [51000, 51500, 51000][$index]], JSON_THROW_ON_ERROR),
            ]);

            return [$handle, $user];
        });

        $multi = curl_multi_init();
        foreach ($requests as [$handle]) {
            curl_multi_add_handle($multi, $handle);
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $responses = $requests->map(function (array $request) use ($multi): array {
            [$handle, $user] = $request;
            $body = curl_multi_getcontent($handle);
            $response = ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => json_decode($body, true), 'user' => $user];
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);

            return $response;
        });
        curl_multi_close($multi);

        $this->assertCount(2, $responses->where('status', 200));
        $this->assertCount(1, $responses->where('status', 422));

        $auction->refresh();
        $this->assertSame('51500.00', $auction->current_price);
        $this->assertSame($users[1]->id, $auction->leading_bidder_id);
        $this->assertSame(2, $auction->bids()->count());
        $this->assertSame(2, $auction->proxyBids()->count());
    }
}
