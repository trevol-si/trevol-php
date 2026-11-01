<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->debitNotes->previewDebitNote(
    note_input: [
        'external_id' => 'INT-2026-000012',
        'concept' => '1',
        'description' => 'Intereses de mora de la factura de septiembre',
        'invoice' => ['id' => 'inv_3f0c1a2b3f0c1a2b3f0c1a2b3f0c1a2b'],
        'items' => [
            [
                'description' => 'Intereses de mora',
                'unit' => '94',
                'quantity' => '1',
                'unit_price' => '45000',
            ],
        ],
    ],
);
echo $resultado, PHP_EOL;
