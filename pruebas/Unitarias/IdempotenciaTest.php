<?php

declare(strict_types=1);

namespace Trevol\Pruebas\Unitarias;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trevol\Exception\ConfigurationException;
use Trevol\Exception\DianRejectedException;
use Trevol\Pruebas\Banco;

final class IdempotenciaTest extends TestCase
{
    public function testGeneraUnaClaveYLaRepiteEnLosTresIntentos(): void
    {
        $b = new Banco([
            Banco::problema(503, 'dian_unavailable', ['retryable' => true]), Banco::problema(503, 'dian_unavailable', ['retryable' => true]),
            Banco::json(201, Banco::DOCUMENTO),
        ]);
        $b->trevol->invoices->createInvoice(Banco::ENTRADA_FACTURA);
        $claves = array_map(static fn ($p) => $p['cabeceras']['idempotency-key'] ?? null, $b->peticiones);
        $this->assertCount(3, $claves);
        $this->assertSame(['00000000-0000-4000-8000-000000000001'], array_values(array_unique($claves)));
        $this->assertCount(1, array_unique(array_column($b->peticiones, 'cuerpo'))); // el cuerpo viaja entero en cada intento
        $this->assertStringContainsString('"external_id":"VENTA-2026-000123"', str_replace(' ', '', $b->peticiones[2]['cuerpo']));
        $this->assertCount(2, $b->esperas);
    }

    public function testRespetaLaClaveDeLaPersona(): void
    {
        $b = new Banco([Banco::json(201, Banco::DOCUMENTO)]);
        $b->trevol->invoices->createInvoice(Banco::ENTRADA_FACTURA, 'VENTA-2026-000123');
        $this->assertSame('VENTA-2026-000123', $b->peticiones[0]['cabeceras']['idempotency-key']);
    }

    /** @return array<string, array{0: string}> */
    public static function clavesMalas(): array
    {
        return ['espacio' => ['con espacio'], 'larga' => [str_repeat('x', 256)], 'tilde' => ['tilde-é']];
    }

    #[DataProvider('clavesMalas')]
    public function testUnaClaveMalaSeRechazaLocalmenteSinRed(string $mala): void
    {
        $b = new Banco([Banco::json(201, Banco::DOCUMENTO)]);
        try {
            $b->trevol->invoices->createInvoice(Banco::ENTRADA_FACTURA, $mala);
            $this->fail('debía lanzar');
        } catch (ConfigurationException) {
            $this->assertSame([], $b->peticiones);
        }
    }

    public function testLaLlevaElErrorParaReintentarMasTardeConLaMisma(): void
    {
        $b = new Banco([Banco::problema(422, 'dian_rejected')]);
        try {
            $b->trevol->invoices->createInvoice(Banco::ENTRADA_FACTURA);
            $this->fail('debía lanzar');
        } catch (DianRejectedException $e) {
            $this->assertSame('00000000-0000-4000-8000-000000000001', $e->getIdempotencyKey());
        }
    }

    public function testCrearCorregirYLaRecargaLaLlevanElRestoNo(): void
    {
        $segunLaRuta = static function (array $p) {
            if (str_ends_with($p['ruta'], '/top_ups')) {
                return Banco::json(201, ['object' => 'top_up', 'reference' => 'TRV-REC-1', 'amount_cop' => 50000, 'status' => 'prepared']);
            }
            if (str_ends_with($p['ruta'], '/webhook_endpoints')) {
                return Banco::json(201, ['id' => 'we_' . str_repeat('a', 32), 'object' => 'webhook_endpoint', 'environment' => 'test']);
            }
            if ($p['metodo'] === 'POST' && str_ends_with($p['ruta'], '/invoices')) {
                return Banco::json(201, Banco::DOCUMENTO);
            }

            return Banco::json(200, Banco::DOCUMENTO);
        };
        $b = new Banco([$segunLaRuta]);
        $t = $b->trevol;
        $t->invoices->createInvoice(Banco::ENTRADA_FACTURA);
        $t->invoices->correctInvoice(Banco::ID_DOC, Banco::ENTRADA_FACTURA);
        $t->balance->createBalanceTopUp(['amount_cop' => 50000]);
        $t->invoices->retrieveInvoice(Banco::ID_DOC);
        $t->invoices->sendInvoice(Banco::ID_DOC);
        $t->webhookEndpoints->createWebhookEndpoint(['url' => 'https://tu-servidor.co/h', 'events' => ['*']]);
        $t->invoices->previewInvoice(Banco::ENTRADA_FACTURA);
        $vistas = array_map(
            static fn ($p) => $p['metodo'] . ' ' . explode('?', $p['ruta'])[0] . ' ' . (isset($p['cabeceras']['idempotency-key']) ? 'True' : 'False'),
            $b->peticiones
        );
        $this->assertSame([
            'POST /v1/invoices True', 'PUT /v1/invoices/' . Banco::ID_DOC . ' True', 'POST /v1/balance/top_ups True',
            'GET /v1/invoices/' . Banco::ID_DOC . ' False', 'POST /v1/invoices/' . Banco::ID_DOC . '/send False',
            'POST /v1/webhook_endpoints False', 'POST /v1/invoices/preview False',
        ], $vistas);
    }
}
