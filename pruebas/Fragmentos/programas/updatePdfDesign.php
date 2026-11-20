<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->pdfDesigns->updatePdfDesign(
    id: 'pdd_3f0c1a2b3f0c1a2b3f0c1a2b3f0c1a2b',
    pdf_design_update: ['accent_color' => '#0a64a8', 'expected_version' => 1],
);
echo $resultado, PHP_EOL;
