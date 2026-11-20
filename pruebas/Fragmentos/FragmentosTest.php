<?php

declare(strict_types=1);

namespace Trevol\Pruebas\Fragmentos;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Los fragmentos de la documentación, como programas (docs/api/43 §2.9): se COMPILAN (`php -l`) y se CORREN contra Prism. Un fragmento que
 * no compila, o que Prism rechaza porque el cuerpo no cumple la especificación, rompe esta suite.
 *
 *     sdks/herramientas/prism.sh -- bash -c 'cd sdks/php && vendor/bin/phpunit --testsuite fragmentos'
 */
final class FragmentosTest extends TestCase
{
    private const PROGRAMAS = __DIR__ . '/programas';
    /**
     * Las operaciones de /v1/account (las de `llave: 'administracion'` en operaciones.json) piden una llave de administración: el SDK se
     * niega a llamarlas con una tvl_ (ConfigurationException antes de la red), así que su programa corre con una tva_ de prueba en
     * TREVOL_API_KEY (prism.sh exporta una tvl_). Prism solo exige que haya Authorization.
     */
    private const LLAVE_ADMINISTRACION = 'tva_test_c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4c4';

    /** @return array<string, mixed> */
    private static function datos(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../datos/fragmentos.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{0: string}> */
    public static function operaciones(): array
    {
        $ops = array_keys(self::datos()['fragmentos']);
        sort($ops);

        return array_combine($ops, array_map(static fn ($o) => [$o], $ops));
    }

    /** @return array<string, string> la columna `llave` de cada operación */
    private static function llaves(): array
    {
        $ops = json_decode(file_get_contents(__DIR__ . '/../datos/operaciones.json'), true, 512, JSON_THROW_ON_ERROR);

        return array_column($ops, 'llave', 'operationId');
    }

    public function testLaLlaveDeAdministracionDePruebaYLasDiezOperacionesQueLaUsan(): void
    {
        $this->assertMatchesRegularExpression('/^tva_test_[0-9a-f]{64}$/', self::LLAVE_ADMINISTRACION);
        $admin = array_keys(array_filter(self::llaves(), static fn ($l) => $l === 'administracion'));
        $this->assertCount(10, $admin);
        foreach ($admin as $op) {
            $this->assertArrayHasKey($op, self::operaciones());
        }
    }

    public function testLaEstructura(): void
    {
        $ops = array_keys(self::operaciones());
        $this->assertCount(178, $ops); // 0.4.0: + 19
        $archivos = array_map(static fn ($f) => basename($f, '.php'), glob(self::PROGRAMAS . '/*.php'));
        sort($archivos);
        $this->assertSame($ops, $archivos);
        foreach ($ops as $op) {
            $this->assertStringContainsString('TREVOL_API_KEY', self::datos()['fragmentos'][$op]['php']);
            $this->assertStringContainsString('new Trevol(', self::datos()['fragmentos'][$op]['php']);
        }
    }

    #[DataProvider('operaciones')]
    public function testElFragmentoCompila(string $op): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(self::PROGRAMAS . "/{$op}.php") . ' 2>&1', $salida, $codigo);
        $this->assertSame(0, $codigo, implode("\n", $salida));
    }

    #[DataProvider('operaciones')]
    public function testElFragmentoCorreContraPrism(string $op): void
    {
        if (!getenv('TREVOL_PRISM_URL')) {
            $this->markTestSkipped("necesita Prism: sdks/herramientas/prism.sh -- bash -c 'cd sdks/php && vendor/bin/phpunit --testsuite fragmentos'");
        }
        $entorno = (self::llaves()[$op] ?? null) === 'administracion'
            ? 'env TREVOL_API_KEY=' . escapeshellarg(self::LLAVE_ADMINISTRACION) . ' '
            : '';
        exec($entorno . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::PROGRAMAS . "/{$op}.php") . ' 2>&1', $salida, $codigo);
        $this->assertSame(0, $codigo, implode("\n", $salida));
        $this->assertDoesNotMatchRegularExpression('/Warning|Deprecated|Notice|Fatal/', implode("\n", $salida));
    }
}
