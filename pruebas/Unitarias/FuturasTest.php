<?php

declare(strict_types=1);

namespace Trevol\Pruebas\Unitarias;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trevol\Exception\TrevolException;
use Trevol\Pruebas\Banco;
use Trevol\Webhook;

final class FuturasTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function respuestas(): array
    {
        $salida = [];
        foreach (Banco::datos('respuestas-futuras.json')['casos'] as $c) {
            if (isset($c['operacion'])) {
                $salida[$c['nombre']] = [$c];
            }
        }

        return $salida;
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function eventos(): array
    {
        $salida = [];
        foreach (Banco::datos('respuestas-futuras.json')['casos'] as $c) {
            if (isset($c['evento'])) {
                $salida[$c['nombre']] = [$c];
            }
        }

        return $salida;
    }

    /** @param array<string, mixed> $caso */
    #[DataProvider('respuestas')]
    public function testRespuestasDelFuturoNoRompen(array $caso): void
    {
        $tipo = $caso['estado'] >= 400 ? 'application/problem+json' : 'application/json';
        $b = new Banco([new Response($caso['estado'], ['Content-Type' => $tipo] + ($caso['cabeceras'] ?? []), json_encode($caso['cuerpo']))], opciones: ['maxRetries' => 0]);
        $llamar = $caso['operacion'] === 'listInvoices'
            ? static fn () => $b->trevol->invoices->listInvoices()
            : static fn () => $b->trevol->invoices->retrieveInvoice('inv_x');
        $e = $caso['esperado'];
        if ($caso['estado'] < 400) {
            $r = $llamar();
            if (isset($e['cantidad'])) {
                $this->assertCount($e['cantidad'], $r->getData());
                $this->assertSame($e['has_more'], $r->getHasMore());
            } else {
                $this->assertSame($e['id'], $r->getId());
                $this->assertSame($e['status'], (string) $r->getStatus());
            }

            return;
        }
        try {
            $llamar();
            $this->fail('debía lanzar');
        } catch (TrevolException $x) {
            $this->assertSame('Trevol\\Exception\\' . str_replace('Error', 'Exception', $e['clase']), $x::class);
            $this->assertSame($e['code'], $x->getTrevolCode());
            $this->assertSame($e['status'], $x->getStatus());
            if (isset($e['requestId'])) {
                $this->assertSame($e['requestId'], $x->getRequestId());
            }
        }
    }

    /** @param array<string, mixed> $caso */
    #[DataProvider('eventos')]
    public function testEventosDelFuturo(array $caso): void
    {
        $clave = '0123456789abcdef0123456789abcdef';
        $secreto = 'whsec_' . base64_encode($clave);
        $ts = 1793491200;
        $cuerpo = json_encode($caso['evento'], JSON_UNESCAPED_UNICODE);
        $firma = 'v1,' . base64_encode(hash_hmac('sha256', "{$caso['evento']['id']}.{$ts}.{$cuerpo}", $clave, true));
        $wh = new Webhook($secreto, static fn (): int => $ts);
        $e = $wh->verify($cuerpo, ['webhook-id' => $caso['evento']['id'], 'webhook-timestamp' => (string) $ts, 'webhook-signature' => $firma]);
        $this->assertSame($caso['esperado']['type'], (string) $e->getType());
        $this->assertSame($caso['esperado']['tieneModelo'], $wh->eventData($e) !== null);
        $this->assertEquals($caso['evento']['data'], json_decode(json_encode($e->getData()), true));
    }
}
