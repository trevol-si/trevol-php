<?php

declare(strict_types=1);

namespace Trevol\Exception;

/** No hubo respuesta: red, DNS, TLS o tiempo agotado. Lleva la Idempotency-Key para reintentar sin duplicar.. */
class ApiConnectionException extends TrevolException
{
}
