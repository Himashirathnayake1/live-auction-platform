<?php

namespace App\Exceptions;

use RuntimeException;

class AuctionException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
