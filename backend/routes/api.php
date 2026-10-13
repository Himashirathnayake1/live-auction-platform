<?php

use App\Http\Controllers\AuctionController;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/auctions', [AuctionController::class, 'index']);
Route::get('/auctions/{auction}', [AuctionController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/auctions', [AuctionController::class, 'store']);
    Route::patch('/auctions/{auction}', [AuctionController::class, 'update']);
    Route::post('/auctions/{auction}/bids', [AuctionController::class, 'bid']);
    Route::post('/auctions/{auction}/close', [AuctionController::class, 'close']);
    Route::post('/auctions/{auction}/cancel', [AuctionController::class, 'cancel']);
});
