<?php

namespace App\Http\Controllers;

use App\Exceptions\AuctionException;
use App\Models\Auction;
use App\Services\AuctionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuctionController extends Controller
{
    public function index(): JsonResponse
    {
        $service = app(AuctionService::class);
        $auctions = Auction::query()->latest()->get()->map(fn (Auction $auction) => $service->syncStatus($auction));

        return response()->json($auctions->load('leadingBidder:id,name'));
    }

    public function show(Auction $auction): JsonResponse
    {
        $auction = app(AuctionService::class)->syncStatus($auction);
        $auction->load(['leadingBidder:id,name', 'winner:id,name']);

        return response()->json([
            'auction' => $auction,
            'bids' => $auction->bids()->with('user:id,name')->latest()->limit(50)->get()->map(fn ($bid) => [
                'id' => $bid->id, 'amount' => $bid->amount, 'type' => $bid->type,
                'user' => $bid->user?->name ? 'Bidder '.substr(md5($bid->user->id), 0, 4) : null,
                'created_at' => $bid->created_at,
            ]),
            'events' => $auction->events()->latest()->limit(50)->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'title' => 'required|string|max:150', 'description' => 'required|string',
            'starting_price' => 'required|numeric|min:0', 'reserve_price' => 'nullable|numeric|min:0',
            'minimum_bid_increment' => 'required|numeric|min:0.01',
            'increment_rules' => 'nullable|array',
            'increment_rules.*.from' => 'required_with:increment_rules|numeric|min:0',
            'increment_rules.*.to' => 'nullable|numeric|gte:increment_rules.*.from',
            'increment_rules.*.increment' => 'required_with:increment_rules|numeric|min:0.01',
            'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at',
            'anti_sniping_seconds' => 'required|integer|min:0', 'extension_seconds' => 'required|integer|min:1',
            'max_extensions' => 'required|integer|min:0', 'status' => 'nullable|in:draft,scheduled',
        ]);
        $data['current_price'] = $data['starting_price'];
        $data['status'] = $data['status'] ?? (now()->gte($data['starts_at']) ? 'live' : 'scheduled');
        if ($data['status'] !== 'draft' && now()->gte($data['starts_at'])) {
            $data['status'] = 'live';
        }
        $auction = Auction::create($data);

        return response()->json($auction, 201);
    }

    public function update(Request $request, Auction $auction): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        if (in_array($auction->status, ['live', 'completed', 'cancelled'], true)) {
            return response()->json(['message' => 'Only draft or scheduled auctions can be edited.'], 409);
        }
        $data = $request->validate([
            'title' => 'sometimes|string|max:150', 'description' => 'sometimes|string',
            'starting_price' => 'sometimes|numeric|min:0', 'reserve_price' => 'nullable|numeric|min:0',
            'minimum_bid_increment' => 'sometimes|numeric|min:0.01', 'increment_rules' => 'nullable|array',
            'increment_rules.*.from' => 'required_with:increment_rules|numeric|min:0',
            'increment_rules.*.to' => 'nullable|numeric|gte:increment_rules.*.from',
            'increment_rules.*.increment' => 'required_with:increment_rules|numeric|min:0.01',
            'starts_at' => 'sometimes|date', 'ends_at' => 'sometimes|date',
            'status' => 'sometimes|in:draft,scheduled',
        ]);
        if (array_key_exists('ends_at', $data)
            && Carbon::parse($data['ends_at'])->lte(Carbon::parse($data['starts_at'] ?? $auction->starts_at))) {
            throw ValidationException::withMessages(['ends_at' => 'The end date must be after the start date.']);
        }
        if (array_key_exists('starting_price', $data)) {
            $data['current_price'] = $data['starting_price'];
        }
        $auction->update($data);

        return response()->json($auction->fresh());
    }

    public function bid(Request $request, Auction $auction, AuctionService $service): JsonResponse
    {
        $data = $request->validate(['maximum_amount' => 'required|numeric|min:0.01', 'idempotency_key' => 'nullable|string|max:100']);
        $data['idempotency_key'] ??= $request->header('Idempotency-Key');
        try {
            $bid = $service->placeBid($auction, $request->user(), (float) $data['maximum_amount'], $data['idempotency_key'] ?? null);

            return response()->json(['bid' => $bid, 'auction' => $auction->fresh(['leadingBidder:id,name'])]);
        } catch (AuctionException $exception) {
            $service->recordRejectedBid($auction, $request->user(), $exception->getMessage());

            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }
    }

    public function close(Auction $auction, AuctionService $service): JsonResponse
    {
        abort_unless(request()->user()->role === 'admin', 403);

        try {
            return response()->json($service->close($auction));
        } catch (AuctionException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }
    }

    public function cancel(Auction $auction, AuctionService $service): JsonResponse
    {
        abort_unless(request()->user()->role === 'admin', 403);

        try {
            return response()->json($service->cancel($auction));
        } catch (AuctionException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }
    }
}
