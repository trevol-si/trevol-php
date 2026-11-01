<?php

declare(strict_types=1);

namespace Trevol\Exception;

/** La firma, la fecha o las cabeceras de un webhook no son válidas. `getReason()`: firma, fecha, cabeceras o cuerpo. */
class WebhookVerificationException extends TrevolException
{
    public function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
