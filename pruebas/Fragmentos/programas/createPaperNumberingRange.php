<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->paperNumberingRanges->createPaperNumberingRange(
    paper_numbering_range_input: [
        'resolution_number' => '18764000004521',
        'prefix' => 'TP',
        'from' => 1,
        'to' => 5000,
        'valid_from' => '2026-01-15',
        'valid_to' => '2028-01-15',
    ],
    // idempotency_key: 'VENTA-2026-000123', // opcional: si falta, el SDK pone una y la repite en los reintentos
);
echo $resultado, PHP_EOL;
