<?php

declare(strict_types=1);

namespace Trevol\Exception;

/** external_id_in_use, invalid_state, duplicate_document, request_in_progress, previous_event_pending, file_unavailable, webhook_limit_reached. */
class ConflictException extends TrevolException
{
}
