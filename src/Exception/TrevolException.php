<?php

declare(strict_types=1);

namespace Trevol\Exception;

/**
 * La base de todos los errores del SDK (docs/api/43 §2.6.5): un `catch (TrevolException)` ya lo atrapa todo.
 *
 * Toda respuesta que no es 2xx llega como una subclase, según el `code` del problem+json (el mismo vocabulario que
 * https://platform.trevol.si/docs/errores). Ninguna lleva la llave ni las cabeceras de la PETICIÓN.
 */
class TrevolException extends \RuntimeException
{
    /**
     * @param list<array{field: ?string, pointer: ?string, message: ?string}> $errors
     * @param array<string, mixed>|null $problem
     * @param array<string, list<string>> $responseHeaders
     */
    public function __construct(
        string $message,
        private readonly ?int $status = null,
        private readonly ?string $trevolCode = null,
        private readonly ?string $type = null,
        private readonly ?string $title = null,
        private readonly ?string $detail = null,
        private readonly ?string $requestId = null,
        private readonly ?bool $retryable = null,
        private readonly ?float $retryAfter = null,
        private readonly array $errors = [],
        private readonly ?string $idempotencyKey = null,
        private readonly ?array $problem = null,
        private readonly array $responseHeaders = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /** El estado HTTP (null si no hubo respuesta). */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /** El `code` del problem+json (`getCode()` ya es el código numérico de PHP). Null si no hay uno. */
    public function getTrevolCode(): ?string
    {
        return $this->trevolCode;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function isRetryable(): ?bool
    {
        return $this->retryable;
    }

    /** Segundos que pidió la API esperar (cabecera `Retry-After` o `retry_after` del cuerpo). */
    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }

    /**
     * Las `errors[]` de un 422: qué campo falló (`pointer`) y por qué.
     *
     * @return list<array{field: ?string, pointer: ?string, message: ?string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** La Idempotency-Key de la llamada: úsala para reintentar más tarde con la MISMA clave. */
    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /**
     * El cuerpo problem+json completo, con los campos extra (`existing_id`, `reason`, `document`, `rules`…).
     *
     * @return array<string, mixed>|null
     */
    public function getProblem(): ?array
    {
        return $this->problem;
    }

    /**
     * Las cabeceras de la RESPUESTA.
     *
     * @return array<string, list<string>>
     */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    /** Clase, `code`, estado, detalle y `request_id`; nunca la llave ni cabeceras de la petición. */
    public function __toString(): string
    {
        $partes = [(new \ReflectionClass($this))->getShortName()];
        if ($this->trevolCode !== null) {
            $partes[] = $this->trevolCode;
        }
        if ($this->status !== null) {
            $partes[] = 'HTTP ' . $this->status;
        }
        $cola = $this->requestId !== null ? " [request_id={$this->requestId}]" : '';

        return implode(' ', $partes) . ': ' . ($this->detail ?? $this->title ?? $this->getMessage()) . $cola;
    }
}
