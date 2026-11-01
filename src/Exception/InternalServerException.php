<?php

declare(strict_types=1);

namespace Trevol\Exception;

/** internal_error y cualquier 5xx sin code conocido. */
class InternalServerException extends TrevolException
{
}
