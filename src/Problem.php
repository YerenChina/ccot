<?php

namespace BushcraftCN\CCOT;

final class Problem extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400, public readonly ?string $providerCode = null, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
