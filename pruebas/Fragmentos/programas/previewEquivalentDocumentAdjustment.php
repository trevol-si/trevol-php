<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->equivalentDocumentAdjustments->previewEquivalentDocumentAdjustment(
    equivalent_document_adjustment_input: [
        'external_id' => 'TIQ-1-DEV',
        'note_type' => 'credit',
        'concept' => '1',
        'equivalent_document' => ['id' => 'pos_3f0c1a2b3f0c1a2b3f0c1a2b3f0c1a2b'],
        'items' => [
            [
                'description' => 'Devolución gaseosa',
                'unit' => '94',
                'quantity' => '1',
                'unit_price' => '3000',
                'taxes' => [['code' => '01', 'rate' => '19']],
            ],
        ],
    ],
);
echo $resultado, PHP_EOL;
