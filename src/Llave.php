<?php

declare(strict_types=1);

namespace Trevol;

use Trevol\Exception\ConfigurationException;

/**
 * La llave: su formato, su tipo y el entorno que se deduce de su prefijo (docs/api/43 §2.6.1 y docs/api/35).
 *
 * - `tvl_test_…` / `tvl_live_…`: la llave de UNA empresa (tipo `api`).
 * - `tva_test_…` / `tva_live_…`: la llave de administración de una cuenta (tipo `account`); en las rutas de una empresa va con
 *   `Trevol-Company` y `Trevol-Environment`.
 */
final class Llave
{
    public const FORMATO = '/^(tvl|tva)_(test|live)_[0-9a-f]{64}$/';
    /** El id de una empresa: `com_` y 32 caracteres hexadecimales en minúscula. */
    public const FORMATO_EMPRESA = '/^com_[0-9a-f]{32}$/';

    /**
     * Valida la llave y devuelve su tipo (`api` para tvl_, `account` para tva_) y su entorno (`test` = habilitación de la DIAN,
     * `live` = producción). El mensaje NUNCA incluye la llave.
     *
     * @return array{tipo: 'api'|'account', entorno: 'test'|'live'}
     */
    public static function analizar(#[\SensitiveParameter] mixed $llave): array
    {
        if (!is_string($llave) || preg_match(self::FORMATO, trim($llave), $m) !== 1) {
            throw new ConfigurationException(
                'La llave de la API no tiene el formato esperado (tvl_test_…, tvl_live_…, tva_test_… o tva_live_… con 64 caracteres hexadecimales). '
                . 'La de una empresa se crea en https://platform.trevol.si/llaves y la de administración en '
                . 'https://platform.trevol.si/administracion; pásala completa o define TREVOL_API_KEY.'
            );
        }

        return ['tipo' => $m[1] === 'tva' ? 'account' : 'api', 'entorno' => $m[2]];
    }

    /**
     * Valida la llave y devuelve su entorno: `test` (habilitación de la DIAN) o `live` (producción).
     *
     * @return 'test'|'live'
     */
    public static function entorno(#[\SensitiveParameter] mixed $llave): string
    {
        return self::analizar($llave)['entorno'];
    }

    /** Valida el id de una empresa (`com_…`). El mensaje no repite el valor (por si alguien pasó ahí la llave). */
    public static function empresa(#[\SensitiveParameter] mixed $empresa): string
    {
        if (!is_string($empresa) || preg_match(self::FORMATO_EMPRESA, trim($empresa)) !== 1) {
            throw new ConfigurationException(
                'company debe ser el id de una empresa de tu cuenta: com_ seguido de 32 caracteres hexadecimales en minúscula '
                . '(lo da $trevol->account->listAccountCompanies()).'
            );
        }

        return trim($empresa);
    }
}
