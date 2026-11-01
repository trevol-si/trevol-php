<?php

declare(strict_types=1);

namespace Trevol;

use Trevol\Exception\ConfigurationException;

/** La llave de la API: su formato y el entorno que se deduce de su prefijo (docs/api/43 §2.6.1). */
final class Llave
{
    public const FORMATO = '/^tvl_(test|live)_[0-9a-f]{64}$/';

    /**
     * Valida la llave y devuelve su entorno: `test` (habilitación de la DIAN) o `live` (producción).
     * El mensaje NUNCA incluye la llave.
     *
     * @return 'test'|'live'
     */
    public static function entorno(#[\SensitiveParameter] mixed $llave): string
    {
        if (!is_string($llave) || preg_match(self::FORMATO, trim($llave), $m) !== 1) {
            throw new ConfigurationException(
                'La llave de la API no tiene el formato esperado (tvl_test_… o tvl_live_… con 64 caracteres hexadecimales). '
                . 'Créala en https://platform.trevol.si/llaves y pásala completa o define TREVOL_API_KEY.'
            );
        }

        return $m[1];
    }
}
