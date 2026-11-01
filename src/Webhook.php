<?php

declare(strict_types=1);

namespace Trevol;

use StandardWebhooks\Webhook as Estandar;
use Trevol\Exception\ConfigurationException;
use Trevol\Exception\WebhookVerificationException;
use Trevol\Generado\Model\Event;
use Trevol\Generado\ObjectSerializer;
use Trevol\Generado\Tablas;

/**
 * Verificación de webhooks (docs/api/43 §2.6.9): Standard Webhooks sobre el CUERPO CRUDO (`file_get_contents('php://input')`).
 *
 * La firma esperada la calcula la biblioteca abierta `standard-webhooks/standard-webhooks` (`Webhook::sign`); la tolerancia de 5 minutos
 * (con reloj inyectable en las pruebas), las cabeceras (en cualquier caja) y la comparación en tiempo constante son de este SDK: la
 * biblioteca no deja inyectar el reloj y falla con avisos si la firma viene mal formada.
 */
final class Webhook
{
    /** Tolerancia de `webhook-timestamp`, en segundos, hacia atrás y hacia adelante. */
    public const TOLERANCIA = 300;

    private readonly Estandar $firmador;
    /** @var \Closure(): int */
    private \Closure $ahora;

    /**
     * @param string $secret el secreto del endpoint (`whsec_…`), que se muestra una sola vez al crearlo o rotarlo.
     * @param (callable(): int)|null $ahora solo para pruebas: el reloj, en segundos.
     */
    public function __construct(#[\SensitiveParameter] string $secret, ?callable $ahora = null)
    {
        $secret = trim($secret);
        if ($secret === '' || $secret === 'whsec_') {
            throw new ConfigurationException('Falta el secreto del webhook (whsec_…). Se muestra una sola vez al crear o rotar el endpoint.');
        }
        $sinPrefijo = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        if (base64_decode($sinPrefijo, true) === false || base64_decode($sinPrefijo, true) === '') {
            throw new ConfigurationException('El secreto del webhook no tiene el formato esperado (whsec_ + base64).');
        }
        $this->firmador = new Estandar($secret);
        $this->ahora = \Closure::fromCallable($ahora ?? static fn (): int => time());
    }

    /**
     * Las tres cabeceras en minúsculas, venga lo que venga (un arreglo de `getallheaders()`, `$request->headers`, un PSR-7…). La primera gana.
     *
     * @param array<array-key, mixed> $cabeceras
     * @return array<string, string>
     */
    public static function normalizarCabeceras(array $cabeceras): array
    {
        $salida = [];
        foreach ($cabeceras as $k => $v) {
            $nombre = strtolower((string) $k);
            if (is_array($v)) {
                $v = $v[0] ?? null;
            }
            if (in_array($nombre, ['webhook-id', 'webhook-timestamp', 'webhook-signature'], true) && $v !== null && !isset($salida[$nombre])) {
                $salida[$nombre] = (string) $v;
            }
        }

        return $salida;
    }

    /**
     * Verifica la firma y la fecha de una entrega y devuelve el evento. Lanza `WebhookVerificationException` si algo no cuadra.
     *
     * Ojo: la verificación NO evita repeticiones dentro de los 5 minutos ni los reintentos de entrega: deduplica por `webhook-id`.
     *
     * @param array<array-key, mixed> $headers
     */
    public function verify(mixed $body, array $headers): Event
    {
        if (!is_string($body)) {
            throw new WebhookVerificationException(
                'El cuerpo del webhook debe ser el CUERPO CRUDO (string), no un arreglo ya decodificado: la firma se calcula sobre los bytes exactos. '
                . 'Léelo con file_get_contents(\'php://input\') (Laravel: $request->getContent()).',
                'cuerpo'
            );
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new WebhookVerificationException('El cuerpo del webhook no es UTF-8.', 'cuerpo');
        }
        $h = self::normalizarCabeceras($headers);
        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $n) {
            if (($h[$n] ?? '') === '') {
                throw new WebhookVerificationException("Falta la cabecera {$n}.", 'cabeceras');
            }
        }
        if (preg_match('/^[0-9]{1,12}$/', $h['webhook-timestamp']) !== 1) {
            throw new WebhookVerificationException('El webhook-timestamp no es un número de segundos.', 'fecha');
        }
        $marca = (int) $h['webhook-timestamp'];
        if (abs(($this->ahora)() - $marca) > self::TOLERANCIA) {
            throw new WebhookVerificationException('La fecha del webhook está fuera de la tolerancia de 5 minutos.', 'fecha');
        }
        try {
            $esperada = explode(',', $this->firmador->sign($h['webhook-id'], $marca, $body), 2)[1] ?? '';
        } catch (\Throwable) {
            throw new WebhookVerificationException('Las cabeceras del webhook no son válidas.', 'cabeceras');
        }
        $coincide = false;
        foreach (explode(' ', $h['webhook-signature']) as $firma) {
            $partes = explode(',', $firma, 2);
            if (count($partes) === 2 && $partes[0] === 'v1' && hash_equals($esperada, $partes[1])) {
                $coincide = true; // basta una válida (rotación del secreto); se recorren todas
            }
        }
        if (!$coincide) {
            throw new WebhookVerificationException('La firma del webhook no coincide: revisa el secreto y que el cuerpo sea el crudo, sin reescribir.', 'firma');
        }
        try {
            $evento = ObjectSerializer::deserialize(json_decode($body, false, 512, JSON_THROW_ON_ERROR), Event::class);
        } catch (\Throwable) {
            throw new WebhookVerificationException('El cuerpo firmado no es un evento válido.', 'cuerpo');
        }
        if (!$evento instanceof Event) {
            throw new WebhookVerificationException('El cuerpo firmado no es un evento válido.', 'cuerpo');
        }

        return $evento;
    }

    /**
     * El `data` del evento como el modelo de su `type` (p. ej. `DocumentEventData` para `invoice.accepted`). Null si el tipo es nuevo y este
     * SDK todavía no lo conoce: el evento no se pierde, `$evento->getData()` sigue ahí como mapa.
     */
    public function eventData(Event $evento): ?object
    {
        $modelo = Tablas::EVENTO_MODELOS[(string) $evento->getType()] ?? null;
        $datos = $evento->getData();
        if ($modelo === null || $datos === null) {
            return null;
        }

        return ObjectSerializer::deserialize(json_decode(json_encode($datos, JSON_THROW_ON_ERROR), false), $modelo);
    }
}
