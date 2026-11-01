<?php

declare(strict_types=1);

namespace Trevol;

use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Trevol\Exception\ApiConnectionException;
use Trevol\Exception\ConfigurationException;
use Trevol\Generado\Tablas;

/**
 * El transporte: lo que hace que las llamadas se comporten igual en los 7 SDK (docs/api/43 §2.6.2 a §2.6.5).
 *
 * Es un middleware de Guzzle que se conecta ANTES de `http_errors`: pone las cabeceras y la Idempotency-Key, reintenta y convierte toda
 * respuesta que no es 2xx en una excepción tipada que atraviesa el código generado sin envolverse (el generado solo atrapa
 * `RequestException` y `ConnectException` de Guzzle).
 *
 * @internal
 */
final class Transporte
{
    private const REINTENTABLES = [429, 500, 502, 503, 504];
    private const EN_CURSO = ['request_in_progress', 'previous_event_pending'];
    private const PROBLEMA = 'application/problem+json';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $exactas = null;
    /** @var list<array{0: string, 1: array<string, mixed>}>|null */
    private static ?array $plantillas = null;

    /**
     * La espera propia antes del intento siguiente: 0,5 · 2^(intento−1), con tope de 8 s, por un factor al azar en [0,75; 1].
     */
    public static function esperaPropia(int $intento, float $azar): float
    {
        return min(0.5 * (2 ** ($intento - 1)), 8.0) * (0.75 + 0.25 * $azar);
    }

    /**
     * Función PURA: ¿se reintenta y cuánto se espera? Las mismas reglas y los mismos casos (`pruebas/datos/transporte.json`) en los 7 SDK.
     *
     * @param array{tipo: string, estado?: ?int, code?: ?string, retryable?: ?bool, retryAfter?: ?float} $resultado
     * @return array{reintentar: bool, espera: ?float, motivo: ?string}
     */
    public static function decidir(int $intento, string $reintento, int $maxReintentos, float $maxRetryAfter, float $azar, array $resultado): array
    {
        $tipo = $resultado['tipo'];
        $estado = $resultado['estado'] ?? null;
        if ($tipo === 'respuesta' && $estado !== null && $estado >= 200 && $estado < 300) {
            return ['reintentar' => false, 'espera' => null, 'motivo' => 'exito'];
        }
        if ($tipo === 'red') {
            $reintentable = $reintento === 'seguro';
        } elseif ($reintento === 'solo_429') {
            $reintentable = $estado === 429;
        } elseif ($estado === 402) {
            $reintentable = false;
        } else {
            $reintentable = in_array($estado, self::REINTENTABLES, true)
                || in_array($resultado['code'] ?? null, self::EN_CURSO, true)
                || ($resultado['retryable'] ?? null) === true;
        }
        if (!$reintentable) {
            return ['reintentar' => false, 'espera' => null, 'motivo' => null];
        }
        if ($intento > $maxReintentos) {
            return ['reintentar' => false, 'espera' => null, 'motivo' => 'agotado'];
        }
        $propia = self::esperaPropia($intento, $azar);
        $pedida = $tipo === 'respuesta' ? (float) ($resultado['retryAfter'] ?? 0.0) : 0.0;
        if ($pedida > $maxRetryAfter) {
            return ['reintentar' => false, 'espera' => null, 'motivo' => 'retry_after_excede'];
        }

        return ['reintentar' => true, 'espera' => max($pedida, $propia), 'motivo' => null];
    }

    /** La Idempotency-Key de la persona, validada: 1 a 255 caracteres ASCII visibles. */
    public static function validarClave(string $clave): void
    {
        if (preg_match('/^[!-~]{1,255}$/', $clave) !== 1) {
            throw new ConfigurationException('La idempotencyKey debe tener de 1 a 255 caracteres ASCII visibles (sin espacios).');
        }
    }

    /** @return array<string, mixed>|null la operación (tablas) de un método y una ruta SIN el prefijo /v1 */
    public static function operacion(string $metodo, string $ruta): ?array
    {
        if (self::$exactas === null) {
            self::$exactas = [];
            self::$plantillas = [];
            foreach (Tablas::OPERACIONES as $o) {
                if (!str_contains($o['ruta'], '{')) {
                    self::$exactas[$o['metodo'] . ' ' . $o['ruta']] = $o;
                } else {
                    self::$plantillas[] = [
                        '#^' . preg_replace('/\\\\\{[a-z_]+\\\\\}/', '[^/]+', preg_quote($o['ruta'], '#')) . '$#',
                        $o,
                    ];
                }
            }
        }
        // Las rutas fijas ganan a las plantillas (GET /radian_events/tacit_acceptance frente a GET /radian_events/{id}).
        if (isset(self::$exactas["{$metodo} {$ruta}"])) {
            return self::$exactas["{$metodo} {$ruta}"];
        }
        foreach (self::$plantillas ?? [] as [$re, $o]) {
            if ($o['metodo'] === $metodo && preg_match($re, $ruta) === 1) {
                return $o;
            }
        }

        return null;
    }

    private static function aceptar(?string $binario): string
    {
        return match ($binario) {
            'pdf' => 'application/pdf, ' . self::PROBLEMA,
            'xml' => 'application/xml, ' . self::PROBLEMA,
            'zip' => 'application/zip, ' . self::PROBLEMA,
            default => 'application/json, ' . self::PROBLEMA,
        };
    }

    /** El middleware de Guzzle. */
    public static function middleware(Opciones $o): callable
    {
        return static function (callable $handler) use ($o): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $o) {
                $metodo = strtoupper($request->getMethod());
                $ruta = $request->getUri()->getPath();
                if ($o->rutaBase !== '' && str_starts_with($ruta, $o->rutaBase)) {
                    $ruta = substr($ruta, strlen($o->rutaBase));
                }
                $op = self::operacion($metodo, $ruta);
                $reintento = $op['reintento'] ?? ($metodo === 'GET' ? 'seguro' : 'solo_429');

                // La Idempotency-Key: una por llamada, la misma en todos los reintentos.
                $clave = null;
                if ($op !== null && $op['idempotente']) {
                    $dada = $request->getHeaderLine('Idempotency-Key');
                    if ($request->hasHeader('Idempotency-Key')) {
                        self::validarClave($dada);
                        $clave = $dada;
                    } else {
                        $clave = ($o->uuid)();
                    }
                    $request = $request->withHeader('Idempotency-Key', $clave);
                } else {
                    $request = $request->withoutHeader('Idempotency-Key');
                }
                $request = $request
                    ->withHeader('Authorization', 'Bearer ' . $o->llave)
                    ->withHeader('Trevol-Version', $o->apiVersion)
                    ->withHeader('User-Agent', $o->userAgent)
                    ->withHeader('Accept', self::aceptar($op['binario'] ?? null));

                for ($intento = 1;; ++$intento) {
                    $cuerpo = $request->getBody();
                    if ($intento > 1 && $cuerpo->isSeekable()) {
                        $cuerpo->rewind(); // el cuerpo viaja entero en cada intento
                    }
                    $respuesta = null;
                    $causa = null;
                    try {
                        $respuesta = $handler($request, $options)->wait();
                    } catch (TransferException $e) {
                        if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                            $respuesta = $e->getResponse();
                        } else {
                            $causa = $e;
                        }
                    }
                    if ($respuesta instanceof ResponseInterface) {
                        $estado = $respuesta->getStatusCode();
                        if ($estado >= 200 && $estado < 300) {
                            return Create::promiseFor($respuesta);
                        }
                        $error = Errores::deRespuesta($estado, (string) $respuesta->getBody(), $respuesta->getHeaders(), $clave);
                        $decision = self::decidir($intento, $reintento, $o->maxReintentos, $o->maxRetryAfter, ($o->azar)(), [
                            'tipo' => 'respuesta', 'estado' => $estado, 'code' => $error->getTrevolCode(),
                            'retryable' => $error->isRetryable(), 'retryAfter' => $error->getRetryAfter(),
                        ]);
                        if (!$decision['reintentar']) {
                            throw $error;
                        }
                    } else {
                        $decision = self::decidir($intento, $reintento, $o->maxReintentos, $o->maxRetryAfter, ($o->azar)(), ['tipo' => 'red']);
                        if (!$decision['reintentar']) {
                            $detalle = $causa !== null ? mb_substr($causa->getMessage(), 0, 200) : 'no hubo respuesta';
                            throw new ApiConnectionException(
                                "No pude conectar con la API de Trevol ({$detalle}).",
                                idempotencyKey: $clave,
                                previous: $causa,
                            );
                        }
                    }
                    ($o->dormir)($decision['espera'] ?? 0.0);
                }
            };
        };
    }
}
