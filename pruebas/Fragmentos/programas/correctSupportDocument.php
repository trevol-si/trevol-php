<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->supportDocuments->correctSupportDocument(
    id: 'sd_3f0c1a2b3f0c1a2b3f0c1a2b3f0c1a2b',
    support_document_input: [
        'external_id' => 'OC-991',
        'seller' => [
            'resident' => true,
            'person_type' => 'natural',
            'document_type' => '31',
            'document_number' => '1020304050',
            'name' => 'PEREZ GOMEZ ANA MARIA',
            'address' => 'Vereda El Silencio, finca La Esperanza',
            'municipality_code' => '25053',
            'postal_code' => '252007',
            'tax_responsibilities' => ['R-99-PN'],
        ],
        'items' => [
            [
                'description' => 'Leche cruda',
                'unit' => 'LTR',
                'quantity' => '120',
                'unit_price' => '1800',
            ],
        ],
        'payment' => ['form' => 'cash', 'methods' => [['code' => '10']]],
        'notes' => ['Compra de la semana 42 de octubre'],
    ],
    // idempotency_key: 'VENTA-2026-000123', // opcional: si falta, el SDK pone una y la repite en los reintentos
);
echo $resultado, PHP_EOL;
