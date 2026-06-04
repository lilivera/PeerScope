<?php

namespace App\Services\Collection;

use RuntimeException;

class CollectionHttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
