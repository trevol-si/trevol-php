<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->creditNotes->correctCreditNote(
    id: 'cn_3f0c1a2b3f0c1a2b3f0c1a2b3f0c1a2b',
    note_input: [
        'external_id' => 'DEV-2026-000045',
        'concept' => '1',
        'description' => 'Devolución de una unidad por empaque dañado',
        'invoice' => ['id' => 'inv_3f0c1a2b3f0c1a2b3f0c1a2b3f0c1a2b'],
        'items' => [
            [
                'description' => 'Café especial 500 g',
                'code' => 'CAF-500',
                'unit' => '94',
                'quantity' => '1',
                'unit_price' => '32000',
                'taxes' => [['code' => '01', 'rate' => '19']],
            ],
        ],
    ],
    // idempotency_key: 'VENTA-2026-000123', // opcional: si falta, el SDK pone una y la repite en los reintentos
);
echo $resultado, PHP_EOL;
