<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->products->createProduct(
    catalog_product_input: [
        'external_id' => 'ERP-SKU-CAF500',
        'code' => 'CAF-500',
        'name' => 'Café especial tostado 500 g',
        'unit' => '94',
        'unit_price' => '32000',
        'taxes' => [['code' => '01', 'rate' => '5']],
    ],
    // idempotency_key: 'VENTA-2026-000123', // opcional: si falta, el SDK pone una y la repite en los reintentos
);
echo $resultado, PHP_EOL;
