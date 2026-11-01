<?php

declare(strict_types=1);

namespace Trevol\Pruebas\Unitarias;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trevol\Exception\ConfigurationException;
use Trevol\Exception\WebhookVerificationException;
use Trevol\Generado\Model\DocumentEventData;
use Trevol\Pruebas\Banco;
use Trevol\Webhook;

final class WebhookTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function casos(): array
    {
        $salida = [];
        foreach (Banco::datos('webhooks.json')['casos'] as $c) {
            $salida[$c['nombre']] = [$c];
        }

        return $salida;
    }

    /** @return array<string, mixed> */
    private static function ok(): array
    {
        return self::casos()['valido'][0];
    }

    private static function verificador(string $secreto, int $ahora): Webhook
    {
        return new Webhook($secreto, static fn (): int => $ahora);
    }

    public function testLaToleranciaEsDe5Minutos(): void
    {
        $this->assertSame(300, Banco::datos('webhooks.json')['tolerancia']);
        $this->assertSame(300, Webhook::TOLERANCIA);
    }

    /** @param array<string, mixed> $caso */
    #[DataProvider('casos')]
    public function testVectoresDePruebasDatosWebhooksJson(array $caso): void
    {
        $wh = self::verificador($caso['secreto'], $caso['ahora']);
        if ($caso['valido']) {
            $e = $wh->verify($caso['cuerpo'], $caso['cabeceras']);
            $this->assertSame($caso['evento']['id'], $e->getId());
            $this->assertSame($caso['evento']['type'], (string) $e->getType());

            return;
        }
        try {
            $wh->verify($caso['cuerpo'], $caso['cabeceras']);
            $this->fail('debía rechazarse');
        } catch (WebhookVerificationException $e) {
            $this->assertSame($caso['motivo'], $e->getReason());
        }
    }

    public function testCabecerasDeCualquierCajaValoresEnListaYLaPrimeraRepetidaGana(): void
    {
        $ok = self::ok();
        $wh = self::verificador($ok['secreto'], $ok['ahora']);
        $c = $ok['cabeceras'];
        $this->assertNotNull($wh->verify($ok['cuerpo'], ['Webhook-Id' => $c['webhook-id'], 'WEBHOOK-TIMESTAMP' => [$c['webhook-timestamp']], 'webhook-signature' => $c['webhook-signature']])->getId());
        $this->assertNotNull($wh->verify($ok['cuerpo'], ['webhook-id' => [$c['webhook-id'], 'evt_otro']] + $c)->getId());
    }

    public function testUnCuerpoYaDecodificadoSeRechazaDiciendoComoLeerElCrudo(): void
    {
        $ok = self::ok();
        try {
            self::verificador($ok['secreto'], $ok['ahora'])->verify(json_decode($ok['cuerpo'], true), $ok['cabeceras']);
            $this->fail('debía rechazarse');
        } catch (WebhookVerificationException $e) {
            $this->assertStringContainsString('CRUDO', $e->getMessage());
            $this->assertStringContainsString('php://input', $e->getMessage());
            $this->assertSame('cuerpo', $e->getReason());
        }
    }

    public function testUnSecretoVacioOConBasuraEsConfigurationExceptionYNoSeFiltra(): void
    {
        foreach (['', '   ', 'whsec_'] as $malo) {
            try {
                new Webhook($malo);
                $this->fail('debía lanzar');
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            new Webhook('whsec_@@@@@');
            $this->fail('debía lanzar');
        } catch (ConfigurationException $e) {
            $this->assertStringNotContainsString('@@@', $e->getMessage());
        }
    }

    public function testEventDataDaElModeloSegunElTypeYUnTypeNuevoDaNull(): void
    {
        $ok = self::ok();
        $wh = self::verificador($ok['secreto'], $ok['ahora']);
        $e = $wh->verify($ok['cuerpo'], $ok['cabeceras']);
        $d = $wh->eventData($e);
        $this->assertInstanceOf(DocumentEventData::class, $d);
        $this->assertSame('accepted', $d->getStatus());
        $e->setType('invoice.archived');
        $this->assertNull($wh->eventData($e));
    }

    public function testElMensajeDeUnRechazoNoIncluyeElSecreto(): void
    {
        $ok = self::ok();
        try {
            self::verificador($ok['secreto'], $ok['ahora'])->verify($ok['cuerpo'] . ' ', $ok['cabeceras']);
            $this->fail('debía rechazarse');
        } catch (WebhookVerificationException $e) {
            $this->assertStringNotContainsString(substr($ok['secreto'], 6), $e->getMessage() . $e->getTraceAsString());
        }
    }
}
