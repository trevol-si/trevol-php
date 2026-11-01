<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->invoices->createInvoice(
    invoice_input: [
        'external_id' => 'EXP-77',
        'document_type' => '02',
        'currency' => [
            'code' => 'USD',
            'exchange_rate' => '3950.12',
            'rate_date' => '2026-10-21',
        ],
        'delivery_terms' => ['incoterm' => 'FOB', 'conditions' => 'Puerto de Cartagena'],
        'customer' => [
            'document_type' => '50',
            'document_number' => 'US123456789',
            'name' => 'ACME INC',
            'country' => 'US',
            'city' => 'Miami',
            'region' => 'Florida',
            'postal_code' => '33131',
            'address' => '200 S Biscayne Blvd',
        ],
        'items' => [
            [
                'description' => 'Consultoría',
                'quantity' => '1',
                'unit_price' => '1000',
                'taxes' => [['code' => '01', 'excluded' => true]],
            ],
        ],
        'payment' => ['form' => 'credit', 'term_days' => 30],
    ],
    // idempotency_key: 'VENTA-2026-000123', // opcional: si falta, el SDK pone una y la repite en los reintentos
);
echo $resultado, PHP_EOL;
