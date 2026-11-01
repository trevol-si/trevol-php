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

    public function testLaEstructura(): void
    {
        $ops = array_keys(self::operaciones());
        $this->assertCount(109, $ops);
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
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::PROGRAMAS . "/{$op}.php") . ' 2>&1', $salida, $codigo);
        $this->assertSame(0, $codigo, implode("\n", $salida));
        $this->assertDoesNotMatchRegularExpression('/Warning|Deprecated|Notice|Fatal/', implode("\n", $salida));
    }
}
