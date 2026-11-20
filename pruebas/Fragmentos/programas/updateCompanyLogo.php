<?php

// Generado por sdks/herramientas/fragmentos.mjs: el fragmento de la documentación, apuntando a Prism. No editar.
use Trevol\Trevol;

require __DIR__ . '/../../../vendor/autoload.php';

$trevol = new Trevol(getenv('TREVOL_API_KEY'), baseUrl: getenv('TREVOL_PRISM_URL'), maxRetries: 0); // tvl_test_… → $trevol->environment === 'test'

$resultado = $trevol->companySettings->updateCompanyLogo(
    company_logo_input: [
        'image_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAGAAAAAgAQMAAADXDu8XAAAABlBMVEX///8KZKiBydPRAAAAJ0lEQVR42mNgIAPw////AcZmQpagAcf+/////////0BDe+jlH3IAAC07DVBS46jVAAAAAElFTkSuQmCC',
    ],
);
echo $resultado, PHP_EOL;
