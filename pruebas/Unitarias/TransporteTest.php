<?php

declare(strict_types=1);

namespace Trevol\Pruebas\Unitarias;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trevol\Pruebas\Banco;
use Trevol\Transporte;

final class TransporteTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function casos(): array
    {
        $salida = [];
        foreach (Banco::datos('transporte.json')['casos'] as $c) {
            $salida[$c['nombre']] = [$c];
        }

        return $salida;
    }

    public function testHayCasos(): void
    {
        $this->assertGreaterThanOrEqual(50, count(self::casos()));
    }

    /** @param array<string, mixed> $caso */
    #[DataProvider('casos')]
    public function testDecidirConPruebasDatosTransporteJson(array $caso): void
    {
        $r = $caso['resultado'];
        $d = Transporte::decidir($caso['intento'], $caso['reintento'], $caso['maxReintentos'], (float) $caso['maxRetryAfter'], (float) $caso['azar'], [
            'tipo' => $r['tipo'], 'estado' => $r['estado'] ?? null, 'code' => $r['code'] ?? null,
            'retryable' => $r['retryable'] ?? null, 'retryAfter' => isset($r['retryAfter']) ? (float) $r['retryAfter'] : null,
        ]);
        $e = $caso['esperado'];
        $this->assertSame($e['reintentar'], $d['reintentar']);
        if (isset($e['espera'])) {
            $this->assertEqualsWithDelta($e['espera'], $d['espera'], 1e-9);
        }
        if (isset($e['motivo'])) {
            $this->assertSame($e['motivo'], $d['motivo']);
        }
        if (!$e['reintentar']) {
            $this->assertNull($d['espera']);
        }
    }

    public function testLaEsperaPropiaCreceConTopeDe8YUnFactorEntre075Y1(): void
    {
        $this->assertEquals([0.5, 1, 2, 4, 8, 8, 8], array_map(static fn ($n) => Transporte::esperaPropia($n, 1.0), [1, 2, 3, 4, 5, 6, 9]));
        $this->assertSame(0.375, Transporte::esperaPropia(1, 0.0));
        $this->assertEquals(3, Transporte::esperaPropia(4, 0.0));
    }
}
