<?php

declare(strict_types=1);

namespace Trevol;

use Trevol\Exception\AuthenticationException;
use Trevol\Exception\BalanceException;
use Trevol\Exception\ConflictException;
use Trevol\Exception\DianRejectedException;
use Trevol\Exception\DianUnavailableException;
use Trevol\Exception\IdempotencyException;
use Trevol\Exception\InternalServerException;
use Trevol\Exception\InvalidRequestException;
use Trevol\Exception\NotFoundException;
use Trevol\Exception\PermissionDeniedException;
use Trevol\Exception\PreconditionException;
use Trevol\Exception\RateLimitException;
use Trevol\Exception\TrevolException;
use Trevol\Exception\ValidationException;
use Trevol\Generado\Tablas;

/** Convierte una respuesta que no es 2xx en su excepción tipada (docs/api/43 §2.6.5). @internal */
final class Errores
{
    /** @var array<string, class-string<TrevolException>> */
    public const CLASES = [
        'AuthenticationError' => AuthenticationException::class,
        'PermissionDeniedError' => PermissionDeniedException::class,
        'NotFoundError' => NotFoundException::class,
        'InvalidRequestError' => InvalidRequestException::class,
        'IdempotencyError' => IdempotencyException::class,
        'ValidationError' => ValidationException::class,
        'ConflictError' => ConflictException::class,
        'PreconditionError' => PreconditionException::class,
        'DianRejectedError' => DianRejectedException::class,
        'BalanceError' => BalanceException::class,
        'RateLimitError' => RateLimitException::class,
        'DianUnavailableError' => DianUnavailableException::class,
        'InternalServerError' => InternalServerException::class,
    ];

    private const RECORTE = 2048;

    private static function numero(mixed $v): ?float
    {
        if (is_bool($v)) {
            return null;
        }
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '' || !is_numeric($v)) {
                return null;
            }
        }
        if (!is_int($v) && !is_float($v) && !is_string($v)) {
            return null;
        }
        $n = (float) $v;

        return $n >= 0 && is_finite($n) ? $n : null;
    }

    /** El valor de `Retry-After`: segundos o una fecha HTTP. Null si no está o no se entiende. */
    public static function leerRetryAfter(?string $valor, ?int $ahora = null): ?float
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }
        $n = self::numero($valor);
        if ($n !== null) {
            return $n;
        }
        $fecha = strtotime($valor);
        if ($fecha === false) {
            return null;
        }

        return (float) max(0, $fecha - ($ahora ?? time()));
    }

    /**
     * @param array<string, list<string>> $cabeceras
     */
    public static function deRespuesta(int $estado, string $texto, array $cabeceras, ?string $idempotencyKey): TrevolException
    {
        $cab = [];
        foreach ($cabeceras as $k => $v) {
            $cab[strtolower((string) $k)] = is_array($v) ? implode(', ', $v) : (string) $v;
        }
        $cuerpo = null;
        $j = json_decode($texto, true);
        if (is_array($j) && !array_is_list($j)) {
            $cuerpo = $j;
        }
        $cadena = static fn (string $clave): ?string => is_string($cuerpo[$clave] ?? null) ? $cuerpo[$clave] : null;

        $code = $cadena('code');
        $detail = $cadena('detail');
        $title = $cadena('title');
        $errores = [];
        if (is_array($cuerpo['errors'] ?? null)) {
            foreach ($cuerpo['errors'] as $x) {
                if (is_array($x)) {
                    $errores[] = $x + ['field' => null, 'pointer' => null, 'message' => null];
                }
            }
        }
        $retryable = is_bool($cuerpo['retryable'] ?? null) ? $cuerpo['retryable'] : null;
        $retryAfter = self::leerRetryAfter($cab['retry-after'] ?? null) ?? self::numero($cuerpo['retry_after'] ?? null);
        $mensaje = $detail ?? $title ?? ($cuerpo !== null ? "La API respondió {$estado}" : "La API respondió {$estado} sin problem+json");
        if ($code !== null) {
            $nombre = Tablas::CLASE_POR_CODE[$code] ?? null; // un code que el SDK no conoce (la API sumó uno) da la base, nunca rompe
        } else {
            $nombre = Tablas::CLASE_POR_ESTADO[(string) $estado] ?? ($estado >= 500 ? Tablas::CLASE_POR_ESTADO['5xx'] : null);
        }
        $clase = self::CLASES[$nombre ?? ''] ?? TrevolException::class;

        return new $clase(
            $mensaje,
            $estado,
            $code,
            $cadena('type'),
            $title,
            $cuerpo !== null ? $detail : (mb_substr(trim($texto), 0, self::RECORTE) ?: null),
            $cadena('request_id') ?? ($cab['request-id'] ?? null),
            $retryable,
            $retryAfter,
            $errores,
            $idempotencyKey,
            $cuerpo,
            $cabeceras,
        );
    }
}
