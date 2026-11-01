<?php

declare(strict_types=1);

/**
 * Prueba de humo REAL en habilitación (docs/api/43 §2.10.4). NO es parte de la suite: la corre una persona, con una llave de PRUEBAS.
 *
 *     TREVOL_API_KEY=tvl_test_… php pruebas/humo/humo.php
 *
 * Solo lecturas y un preview (el SDK de PHP NO emite). Se NIEGA a correr si la llave no es tvl_test_. TREVOL_BASE_URL (opcional) apunta a
 * otro servidor, p. ej. un Prism local.
 */

use Trevol\Exception\NotFoundException;
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
$paso('previewInvoice', function () use ($trevol) {
    $p = $trevol->invoices->previewInvoice([
        'external_id' => 'HUMO-PREVIEW', 'customer' => ['final_consumer' => true],
        'items' => [['description' => 'Prueba de humo del SDK de Trevol', 'quantity' => '1', 'unit_price' => '1000', 'taxes' => [['code' => '01', 'rate' => '19']]]],
        'payment' => ['form' => 'cash', 'methods' => [['code' => '10']]],
    ]);

    return 'total ' . ($p->getTotals()['total'] ?? '?');
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

echo "\n", $total - $fallas, "/{$total} pasos bien\n";
exit($fallas > 0 ? 1 : 0);
