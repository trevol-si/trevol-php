<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->webhookEndpoints->rotateWebhookEndpointSecret(
    id: 'we_3f0c1a2b3f0c1a2b3f0c1a2b3f0c1a2b',
    webhook_endpoint_rotate_input: ['grace_period_hours' => 24],
);
echo $resultado, PHP_EOL;
