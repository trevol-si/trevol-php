<?php

declare(strict_types=1);

namespace Trevol\Pruebas\Unitarias;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trevol\Exception\ApiConnectionException;
use Trevol\Exception\ConflictException;
use Trevol\Exception\NotFoundException;
use Trevol\Exception\RateLimitException;
use Trevol\Exception\TrevolException;
use Trevol\Exception\ValidationException;
use Trevol\Generado\ApiException;
use Trevol\Pruebas\Banco;

final class ErroresTest extends TestCase
{
    private const ESTADO = [
        'AuthenticationError' => 401, 'PermissionDeniedError' => 403, 'NotFoundError' => 404, 'InvalidRequestError' => 400,
        'IdempotencyError' => 409, 'ValidationError' => 422, 'ConflictError' => 409, 'PreconditionError' => 422, 'DianRejectedError' => 422,
        'BalanceError' => 402, 'RateLimitError' => 429, 'DianUnavailableError' => 503, 'InternalServerError' => 500,
    ];

    /** @return array<string, array{0: string, 1: string}> */
    public static function codes(): array
    {
        $salida = [];
        foreach (Banco::datos('errores.json')['clases'] as $clase => $codes) {
            foreach ($codes as $code) {
                $salida[$code] = [$clase, $code];
            }
        }

        return $salida;
    }

    public function testCubreLos30Code(): void
    {
        $this->assertCount(30, self::codes());
    }

    #[DataProvider('codes')]
    public function testCadaCodeVaASuClaseATravesDeUnMetodoGenerado(string $clase, string $code): void
    {
        $b = new Banco([Banco::problema(self::ESTADO[$clase], $code)], opciones: ['maxRetries' => 0]);
        try {
            $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
            $this->fail('debía lanzar');
        } catch (TrevolException $e) {
            $esperada = 'Trevol\\Exception\\' . str_replace('Error', 'Exception', $clase);
            $this->assertSame($esperada, $e::class);
            $this->assertSame($code, $e->getTrevolCode());
            $this->assertSame(self::ESTADO[$clase], $e->getStatus());
            $this->assertSame('req_c3c3c3c3c3c3c3c3c3c3c3c3', $e->getRequestId());
            $this->assertSame("https://platform.trevol.si/docs/errores#{$code}", $e->getType());
            $this->assertSame($code, $e->getProblem()['code']);
        }
    }

    public function testElErrorNoSeEnvuelveEnApiExceptionDelCodigoGenerado(): void
    {
        $b = new Banco([Banco::problema(404, 'not_found')]);
        $this->expectException(NotFoundException::class);
        try {
            $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
        } catch (TrevolException $e) {
            $this->assertNotInstanceOf(ApiException::class, $e);
            throw $e;
        }
    }

    public function testUnCodeDesconocidoEsLaClaseBaseYNoRompe(): void
    {
        $b = new Banco([Banco::problema(422, 'code_del_futuro')]);
        try {
            $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
            $this->fail('debía lanzar');
        } catch (TrevolException $e) {
            $this->assertSame(TrevolException::class, $e::class);
            $this->assertSame('code_del_futuro', $e->getTrevolCode());
        }
    }

    /** @return array<string, array{0: int, 1: class-string}> */
    public static function noJson(): array
    {
        return [502 => [502, \Trevol\Exception\InternalServerException::class], 404 => [404, NotFoundException::class],
            409 => [409, ConflictException::class], 401 => [401, \Trevol\Exception\AuthenticationException::class]];
    }

    #[DataProvider('noJson')]
    public function testUnaRespuestaQueNoEsJsonDaLaClasePorEstadoConCodeNullYElCuerpoRecortado(int $estado, string $clase): void
    {
        $html = '<html>' . str_repeat('x', 5000) . '</html>';
        $b = new Banco([new Response($estado, ['Content-Type' => 'text/html'], $html)], opciones: ['maxRetries' => 0]);
        try {
            $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
            $this->fail('debía lanzar');
        } catch (TrevolException $e) {
            $this->assertSame($clase, $e::class);
            $this->assertNull($e->getTrevolCode());
            $this->assertSame(2048, strlen((string) $e->getDetail()));
        }
    }

    public function testTomaElRequestIdDeLaCabeceraCuandoElCuerpoNoLoTrae(): void
    {
        $cuerpo = ['type' => 'x', 'title' => 't', 'status' => 404, 'code' => 'not_found', 'retryable' => false];
        $b = new Banco([new Response(404, ['Content-Type' => 'application/problem+json', 'Request-Id' => 'req_d4d4d4d4d4d4d4d4d4d4d4d4'], json_encode($cuerpo))]);
        try {
            $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
            $this->fail('debía lanzar');
        } catch (NotFoundException $e) {
            $this->assertSame('req_d4d4d4d4d4d4d4d4d4d4d4d4', $e->getRequestId());
        }
    }

    public function testValidationExceptionTraeErrorsConFieldPointerYMessage(): void
    {
        $b = new Banco([Banco::problema(422, 'validation_error', ['errors' => [['field' => 'items', 'pointer' => '/items', 'message' => 'Falta']]])]);
        try {
            $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
            $this->fail('debía lanzar');
        } catch (ValidationException $e) {
            $x = $e->getErrors()[0];
            $this->assertSame(['items', '/items', 'Falta'], [$x['field'], $x['pointer'], $x['message']]);
        }
    }

    public function testLosCamposExtraQuedanEnProblem(): void
    {
        $b = new Banco([Banco::problema(409, 'external_id_in_use', ['existing_id' => 'inv_x'])]);
        try {
            $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
            $this->fail('debía lanzar');
        } catch (ConflictException $e) {
            $this->assertSame('inv_x', $e->getProblem()['existing_id']);
        }
    }

    public function testLaLlaveNoApareceEnToStringMensajeNiRastroDeNingunError(): void
    {
        $b = new Banco([Banco::problema(401, 'invalid_api_key')]);
        try {
            $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
            $this->fail('debía lanzar');
        } catch (TrevolException $e) {
            foreach ([(string) $e, $e->getMessage(), $e->getTraceAsString(), print_r($b->trevol, true), json_encode($e->getProblem())] as $t) {
                $this->assertStringNotContainsString(Banco::LLAVE_TEST, $t);
                $this->assertStringNotContainsString(str_repeat('a1', 32), $t);
            }
            $this->assertStringContainsString('invalid_api_key', (string) $e);
            $this->assertStringContainsString('HTTP 401', (string) $e);
            $this->assertStringContainsString('req_', (string) $e);
        }
    }

    public function testUnFalloDeRedEsApiConnectionExceptionConLaClaveYSeReintentaSoloSiEsSeguro(): void
    {
        $caida = new ConnectException('boom', new Request('POST', 'http://x/'));
        $b = new Banco([$caida]);
        try {
            $b->trevol->invoices->createInvoice(Banco::ENTRADA_FACTURA);
            $this->fail('debía lanzar');
        } catch (ApiConnectionException $e) {
            $this->assertNotNull($e->getIdempotencyKey());
            $this->assertCount(3, $b->peticiones); // con clave: 3 intentos
        }
        $b2 = new Banco([$caida]);
        try {
            $b2->trevol->webhookEndpoints->createWebhookEndpoint(['url' => 'https://tu-servidor.co/h', 'events' => ['*']]);
            $this->fail('debía lanzar');
        } catch (ApiConnectionException) {
            $this->assertCount(1, $b2->peticiones); // sin clave: un solo intento
        }
    }

    public function testUn429ConRetryAfterMayorQueElMaximoNoEsperaYLanzaElErrorConRetryAfter(): void
    {
        $b = new Banco([Banco::problema(429, 'rate_limited', ['retryable' => true], ['Retry-After' => '120'])]);
        try {
            $b->trevol->invoices->retrieveInvoice(Banco::ID_DOC);
            $this->fail('debía lanzar');
        } catch (RateLimitException $e) {
            $this->assertSame(120.0, $e->getRetryAfter());
            $this->assertSame([], $b->esperas);
            $this->assertCount(1, $b->peticiones);
        }
    }

    public function testUn202EsUnResultadoNoUnErrorNiUnReintento(): void
    {
        $b = new Banco([Banco::json(202, ['id' => Banco::ID_DOC, 'object' => 'invoice', 'status' => 'processing'])]);
        $doc = $b->trevol->invoices->createInvoice(Banco::ENTRADA_FACTURA); // la operación que declara el 202
        $this->assertSame('processing', $doc->getStatus());
        $this->assertCount(1, $b->peticiones);
    }
}
