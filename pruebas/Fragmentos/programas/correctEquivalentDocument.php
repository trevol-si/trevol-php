<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->equivalentDocuments->correctEquivalentDocument(
    id: 'pos_3f0c1a2b3f0c1a2b3f0c1a2b3f0c1a2b',
    equivalent_document_input: [
        'external_id' => 'TIQ-1',
        'customer' => ['final_consumer' => true],
        'pos' => [
            'register' => [
                'plate' => 'CJ-01',
                'location' => 'Local 1, Jamundí',
                'cashier' => 'PEREZ ANA',
                'type' => 'Caja principal',
            ],
        ],
        'numbering' => ['prefix' => 'POS2'],
        'payment' => ['form' => 'cash', 'methods' => [['code' => '10']]],
        'items' => [
            [
                'description' => 'Gaseosa 400 ml',
                'code' => 'GAS-1',
                'unit' => '94',
                'quantity' => '2',
                'unit_price' => '3000',
                'taxes' => [['code' => '01', 'rate' => '19']],
            ],
            [
                'description' => 'Almuerzo',
                'unit' => '94',
                'quantity' => '1',
                'unit_price' => '18000',
            ],
        ],
    ],
    // idempotency_key: 'VENTA-2026-000123', // opcional: si falta, el SDK pone una y la repite en los reintentos
);
echo $resultado, PHP_EOL;
