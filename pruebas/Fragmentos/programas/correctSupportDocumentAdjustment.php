<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->supportDocumentAdjustments->correctSupportDocumentAdjustment(
    id: 'sda_3f0c1a2b3f0c1a2b3f0c1a2b3f0c1a2b',
    support_document_adjustment_input: [
        'external_id' => 'OC-991-ANULA',
        'concept' => '2',
        'description' => 'Anulación: la compra de leche se registró dos veces en el ERP',
        'support_document' => ['id' => 'sd_3f0c1a2b3f0c1a2b3f0c1a2b3f0c1a2b'],
        'items' => [
            [
                'description' => 'Leche cruda',
                'unit' => 'LTR',
                'quantity' => '120',
                'unit_price' => '1800',
            ],
        ],
    ],
    // idempotency_key: 'VENTA-2026-000123', // opcional: si falta, el SDK pone una y la repite en los reintentos
);
echo $resultado, PHP_EOL;
