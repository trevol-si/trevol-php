<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->webhookEndpoints->createWebhookEndpoint(
    webhook_endpoint_input: [
        'url' => 'https://tu-servidor.co/webhooks/trevol',
        'description' => 'Producción: facturación',
        'events' => ['invoice.accepted', 'invoice.rejected', 'credit_note.accepted'],
    ],
);
echo $resultado, PHP_EOL;
