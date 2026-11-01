<?php

declare(strict_types=1);

namespace Trevol\Pruebas\Unitarias;

use PHPUnit\Framework\TestCase;
use Trevol\Generado\Model\Document;
use Trevol\Generado\Model\InvoiceList;
use Trevol\Paginacion;
use Trevol\Pruebas\Banco;

final class PaginacionTest extends TestCase
{
    private static function doc(string $n): Document
    {
        return new Document(['id' => "inv_{$n}"]);
    }

    private static function pagina(bool $hay, ?string $cursor, string ...$ids): InvoiceList
    {
        return new InvoiceList(['data' => array_map([self::class, 'doc'], $ids), 'has_more' => $hay, 'next_cursor' => $cursor]);
    }

    public function testRecorreTresPaginasFalsasConNextCursorYSeDetiene(): void
    {
        $pedidos = [];
        $paginas = [self::pagina(true, 'inv_2', '1', '2'), self::pagina(true, 'inv_4', '3', '4'), self::pagina(false, null, '5')];
        $ids = [];
        foreach (Paginacion::paginar(function (?string $c) use (&$pedidos, $paginas) {
            $pedidos[] = $c;

            return $paginas[count($pedidos) - 1];
        }) as $f) {
            $ids[] = $f->getId();
        }
        $this->assertSame(['inv_1', 'inv_2', 'inv_3', 'inv_4', 'inv_5'], $ids);
        $this->assertSame([null, 'inv_2', 'inv_4'], $pedidos);
    }

    public function testSiHasMoreVieneSinNextCursorUsaElIdDelUltimo(): void
    {
        $pedidos = [];
        $paginas = [self::pagina(true, null, '1', '2'), self::pagina(false, null, '3')];
        $n = iterator_count(Paginacion::paginar(function (?string $c) use (&$pedidos, $paginas) {
            $pedidos[] = $c;

            return $paginas[count($pedidos) - 1];
        }));
        $this->assertSame(3, $n);
        $this->assertSame([null, 'inv_2'], $pedidos);
    }

    public function testEsPerezosa(): void
    {
        $llamadas = 0;
        $it = Paginacion::paginar(function () use (&$llamadas) {
            ++$llamadas;

            return self::pagina(true, 'inv_2', '1', '2');
        });
        $it->current();
        $this->assertSame(1, $llamadas);
    }

    public function testUnCursorQueNoAvanzaOUnaPaginaVaciaNoDejanElBucleGirando(): void
    {
        $n = 0;
        $vistos = iterator_count(Paginacion::paginar(function () use (&$n) {
            ++$n;

            return self::pagina(true, 'inv_1', '1');
        }));
        $this->assertLessThanOrEqual(3, $vistos);
        $this->assertSame(0, iterator_count(Paginacion::paginar(fn () => self::pagina(true, 'x'))));
    }

    public function testPaginateConUnMetodoGeneradoYElServidorFalso(): void
    {
        $b = new Banco([
            Banco::json(200, ['object' => 'list', 'data' => [['id' => 'inv_1'], ['id' => 'inv_2']], 'has_more' => true, 'next_cursor' => 'inv_2']),
            Banco::json(200, ['object' => 'list', 'data' => [['id' => 'inv_3']], 'has_more' => false, 'next_cursor' => null]),
        ]);
        $t = $b->trevol;
        $ids = [];
        foreach ($t->paginate(fn (?string $cursor) => $t->invoices->listInvoices(2, $cursor)) as $f) {
            $ids[] = $f->getId();
        }
        $this->assertSame(['inv_1', 'inv_2', 'inv_3'], $ids);
        $this->assertSame('/v1/invoices?limit=2', $b->peticiones[0]['ruta']);
        $this->assertSame('/v1/invoices?limit=2&starting_after=inv_2', $b->peticiones[1]['ruta']);
    }
}
