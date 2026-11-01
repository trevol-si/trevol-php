<?php

declare(strict_types=1);

namespace Trevol\Pruebas;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Trevol\Trevol;

/**
 * Un cliente contra un servidor falso: un manejador de Guzzle que contesta con las respuestas dadas (la última se repite) y anota las
 * peticiones. El código generado y el middleware del SDK son los de verdad; solo la red es falsa. El reloj y el azar están inyectados.
 */
final class Banco
{
    public const LLAVE_TEST = 'tvl_test_a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    public const LLAVE_LIVE = 'tvl_live_b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
    public const HEX = '3f0c1a2b3f0c1a2b3f0c1a2b3f0c1a2b';
    public const ID_DOC = 'inv_' . self::HEX;
    public const DOCUMENTO = ['id' => self::ID_DOC, 'object' => 'invoice', 'environment' => 'test', 'status' => 'accepted', 'number' => 'SETP990012345', 'external_id' => 'VENTA-2026-000123'];
    public const ENTRADA_FACTURA = ['external_id' => 'VENTA-2026-000123', 'customer' => ['final_consumer' => true], 'items' => [['description' => 'Café', 'quantity' => '1', 'unit_price' => '1000']]];

    /** @var list<array{metodo: string, ruta: string, cabeceras: array<string, string>, cuerpo: string}> */
    public array $peticiones = [];
    /** @var list<float> */
    public array $esperas = [];
    public Trevol $trevol;
    private int $i = 0;
    private int $n = 0;

    /**
     * @param list<Response|\Throwable|callable> $respuestas
     * @param array<string, mixed> $opciones
     */
    public function __construct(private readonly array $respuestas, string $llave = self::LLAVE_TEST, array $opciones = [])
    {
        $handler = function (RequestInterface $req, array $opts) {
            $cuerpo = (string) $req->getBody();
            $cabeceras = [];
            foreach ($req->getHeaders() as $k => $v) {
                $cabeceras[strtolower($k)] = implode(', ', $v);
            }
            $p = ['metodo' => $req->getMethod(), 'ruta' => $req->getRequestTarget(), 'cabeceras' => $cabeceras, 'cuerpo' => $cuerpo];
            $this->peticiones[] = $p;
            $r = $this->respuestas[min($this->i++, count($this->respuestas) - 1)];
            if (is_callable($r)) {
                $r = $r($p);
            }
            if ($r instanceof \Throwable) {
                return Create::rejectionFor($r);
            }

            return Create::promiseFor($r);
        };
        $this->trevol = new Trevol($llave, ...[
            'baseUrl' => 'http://127.0.0.1:9/v1',
            'handler' => $handler,
            'internos' => [
                'dormir' => function (float $s): void { $this->esperas[] = $s; },
                'azar' => static fn (): float => 1.0,
                'uuid' => function (): string { return sprintf('00000000-0000-4000-8000-%012d', ++$this->n); },
            ],
        ] + $opciones);
    }

    public static function json(int $estado, mixed $cuerpo, array $cabeceras = []): Response
    {
        return new Response($estado, ['Content-Type' => 'application/json'] + $cabeceras, json_encode($cuerpo, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $extra */
    public static function problema(int $estado, string $code, array $extra = [], array $cabeceras = []): Response
    {
        $cuerpo = $extra + [
            'type' => "https://platform.trevol.si/docs/errores#{$code}", 'title' => 'Título', 'status' => $estado, 'code' => $code,
            'retryable' => false, 'detail' => "Detalle de {$code}", 'request_id' => 'req_c3c3c3c3c3c3c3c3c3c3c3c3',
        ];

        return new Response($estado, ['Content-Type' => 'application/problem+json'] + $cabeceras, json_encode($cuerpo, JSON_THROW_ON_ERROR));
    }

    public static function datos(string $nombre): mixed
    {
        return json_decode(file_get_contents(__DIR__ . '/datos/' . $nombre), true, 512, JSON_THROW_ON_ERROR);
    }
}
