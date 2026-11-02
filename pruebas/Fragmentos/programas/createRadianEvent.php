<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->radianEvents->createRadianEvent(
    radian_event_input: [
        'external_id' => 'ERP-ACUSE-DA1234',
        'event_code' => '030',
        'invoice' => [
            'cufe' => 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd',
            'number' => 'DA1234',
            'type' => '01',
            'issuer' => [
                'nit' => '900373427',
                'check_digit' => 6,
                'document_type' => '31',
                'name' => 'DISTRIBUIDORA LOS ANDES SAS',
            ],
        ],
    ],
    // idempotency_key: 'VENTA-2026-000123', // opcional: si falta, el SDK pone una y la repite en los reintentos
);
echo $resultado, PHP_EOL;
