<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->companySettings->updatePdfSettings(
    pdf_settings_update: [
        'letter_design_id' => 'pdd_7d3e9a107d3e9a107d3e9a107d3e9a10',
        'receipt_design_id' => 'pdd_41b2c6f041b2c6f041b2c6f041b2c6f0',
    ],
);
echo $resultado, PHP_EOL;
