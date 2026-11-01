<?php

declare(strict_types=1);

/**
 * Prueba de humo REAL en habilitación (docs/api/43 §2.10.4). NO es parte de la suite: la corre una persona, con una llave de PRUEBAS.
 *
 *     TREVOL_API_KEY=tvl_test_… php pruebas/humo/humo.php
 *
 * Lecturas, un preview y un error tipado (validation_error); con TREVOL_HUMO_EMITIR=1 además EMITE una factura en habilitación, espera a
 * que quede final y descarga el PDF. Se NIEGA a correr si la llave no es tvl_test_. TREVOL_BASE_URL (opcional) apunta a
 * otro servidor, p. ej. un Prism local.
 */

use Trevol\Exception\NotFoundException;
use Trevol\Exception\ValidationException;
use Trevol\Trevol;
use Trevol\Webhook;

require __DIR__ . '/../../vendor/autoload.php';

$llave = getenv('TREVOL_API_KEY') ?: '';
if (preg_match('/^tvl_test_[0-9a-f]{64}$/', $llave) !== 1) {
    fwrite(STDERR, "humo: me niego a correr si TREVOL_API_KEY no es una llave de PRUEBAS (tvl_test_…). Nunca se prueba con tvl_live_.\n");
    exit(2);
}
$base = getenv('TREVOL_BASE_URL');
$trevol = $base ? new Trevol($llave, environment: 'test', baseUrl: $base) : new Trevol($llave, environment: 'test');

$total = 0;
$fallas = 0;
$paso = static function (string $nombre, callable $f) use (&$total, &$fallas): void {
    ++$total;
    try {
        $detalle = $f();
        echo 'ok   ', $nombre, $detalle ? ' — ' . $detalle : '', PHP_EOL;
    } catch (Throwable $e) { // es una prueba de humo: cuenta cualquier fallo
        ++$fallas;
        echo 'FALLA ', $nombre, ' — ', get_class($e), ': ', $e->getMessage(), PHP_EOL;
    }
};

$paso('getHealth', function () use ($trevol) {
    $trevol->general->getHealth();

    return '';
});
$paso('retrieveCompany', function () use ($trevol) {
    $c = $trevol->general->retrieveCompany();
    if ($c->getEnvironment() !== 'test') {
        throw new RuntimeException('la empresa dice environment=' . $c->getEnvironment());
    }

    return $c->getName() . ' (' . $c->getNit() . ')';
});
$factura = static fn (string $externo, ?array $items = null): array => [
    'external_id' => $externo, 'customer' => ['final_consumer' => true],
    'items' => $items ?? [['description' => 'Prueba de humo del SDK de Trevol', 'quantity' => '1', 'unit_price' => '1000', 'taxes' => [['code' => '01', 'rate' => '19']]]],
    'payment' => ['form' => 'cash', 'methods' => [['code' => '10']]],
];
$paso('previewInvoice', function () use ($trevol, $factura) {
    $p = $trevol->invoices->previewInvoice($factura('HUMO-PREVIEW'));

    return 'total ' . ($p->getTotals()['total'] ?? '?');
});
$paso('previewInvoice sin líneas → ValidationException (code validation_error)', function () use ($trevol, $factura) {
    try {
        $trevol->invoices->previewInvoice($factura('HUMO-INVALIDA', []));
    } catch (ValidationException $e) {
        if ($e->getTrevolCode() !== 'validation_error') {
            throw new RuntimeException('code = ' . $e->getTrevolCode());
        }

        return $e->getStatus() . ' ' . $e->getTrevolCode() . ' ' . ($e->getErrors()[0]['pointer'] ?? '');
    }
    throw new RuntimeException('no lanzó ValidationException');
});
$paso('listInvoices + paginate (3)', function () use ($trevol) {
    $trevol->invoices->listInvoices(3);
    $n = 0;
    foreach ($trevol->paginate(fn (?string $c) => $trevol->invoices->listInvoices(3, $c)) as $_) {
        if (++$n === 3) {
            break;
        }
    }

    return "{$n} facturas";
});
$paso('retrieveInvoice inexistente → NotFoundException con request_id', function () use ($trevol) {
    try {
        $trevol->invoices->retrieveInvoice('inv_' . str_repeat('0', 32));
    } catch (NotFoundException $e) {
        if (!str_starts_with((string) $e->getRequestId(), 'req_')) {
            throw new RuntimeException('sin request_id');
        }

        return $e->getRequestId();
    }
    throw new RuntimeException('no lanzó NotFoundException');
});
$paso('Webhook::verify (firmado ahora, con un secreto local)', function () {
    $clave = 'trevol-humo-secreto-de-32-bytes!';
    $id = 'evt_' . str_repeat('1', 32);
    $cuerpo = json_encode(['id' => $id, 'object' => 'event', 'type' => 'webhook.test', 'timestamp' => gmdate('c'), 'environment' => 'test',
        'api_version' => '2026-10-22', 'data' => new stdClass()]);
    $ts = time();
    $firma = 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$cuerpo}", $clave, true));
    $e = (new Webhook('whsec_' . base64_encode($clave)))->verify($cuerpo, ['webhook-id' => $id, 'webhook-timestamp' => (string) $ts, 'webhook-signature' => $firma]);

    return (string) $e->getType();
});

if (getenv('TREVOL_HUMO_EMITIR') === '1') {
    $id = null;
    $paso('createInvoice (EMITE en habilitación)', function () use ($trevol, $factura, &$id) {
        $d = $trevol->invoices->createInvoice($factura('HUMO-PHP-' . time()));
        $id = $d->getId();

        return $id . ' ' . $d->getStatus();
    });
    if ($id !== null) {
        $paso('espera un estado final (hasta 90 s)', function () use ($trevol, $id) {
            $limite = time() + 90;
            for (;;) {
                $estado = (string) $trevol->invoices->retrieveInvoice($id)->getStatus();
                if (!in_array($estado, ['pending', 'sending', 'processing'], true)) {
                    if ($estado !== 'accepted') {
                        throw new RuntimeException("status = {$estado}");
                    }

                    return $estado;
                }
                if (time() > $limite) {
                    throw new RuntimeException("status = {$estado}");
                }
                sleep(3);
            }
        });
        $paso('downloadInvoicePdf', function () use ($trevol, $id) {
            $archivo = $trevol->invoices->downloadInvoicePdf($id);
            $bytes = (string) file_get_contents($archivo->getPathname());
            if (!str_starts_with($bytes, '%PDF')) {
                throw new RuntimeException('no es un PDF');
            }

            return 'PDF ' . strlen($bytes) . ' bytes';
        });
    }
} else {
    echo "(no se emite nada: define TREVOL_HUMO_EMITIR=1 para emitir una factura en habilitación)\n";
}

echo "\n", $total - $fallas, "/{$total} pasos bien\n";
exit($fallas > 0 ? 1 : 0);
