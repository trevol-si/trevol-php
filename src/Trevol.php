<?php

declare(strict_types=1);

namespace Trevol;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Utils;
use Trevol\Exception\ConfigurationException;
use Trevol\Generado\Api\BalanceApi;
use Trevol\Generado\Api\CreditNotesApi;
use Trevol\Generado\Api\DebitNotesApi;
use Trevol\Generado\Api\EquivalentDocumentAdjustmentsApi;
use Trevol\Generado\Api\EquivalentDocumentsApi;
use Trevol\Generado\Api\EventsApi;
use Trevol\Generado\Api\GeneralApi;
use Trevol\Generado\Api\InvoicesApi;
use Trevol\Generado\Api\PayrollAdjustmentsApi;
use Trevol\Generado\Api\PayrollsApi;
use Trevol\Generado\Api\RadianEventsApi;
use Trevol\Generado\Api\RequestsApi;
use Trevol\Generado\Api\SupportDocumentAdjustmentsApi;
use Trevol\Generado\Api\SupportDocumentsApi;
use Trevol\Generado\Api\WebhookEndpointsApi;
use Trevol\Generado\Configuration;
use Trevol\Generado\Tablas;

/**
 * El cliente de la API de Trevol: una propiedad por recurso (`$invoices`, `$creditNotes`, …), cada una con los métodos generados de la
 * especificación (`createInvoice`, `listInvoices`, `retrieveInvoice`…). Documentación: https://platform.trevol.si/docs
 *
 * ```php
 * $trevol = new \Trevol\Trevol(getenv('TREVOL_API_KEY')); // tvl_test_… → $trevol->environment === 'test'
 * ```
 */
final class Trevol
{
    public const VERSION = Tablas::VERSION;
    public const API_VERSION = Tablas::API_VERSION;
    public const BASE_URL = 'https://api.trevol.si/v1';

    /** `test` (llave tvl_test_, habilitación de la DIAN) o `live` (tvl_live_, producción). */
    public readonly string $environment;
    /** El valor de `Trevol-Version` que se manda en cada petición. */
    public readonly string $apiVersion;

    public readonly BalanceApi $balance;
    public readonly CreditNotesApi $creditNotes;
    public readonly DebitNotesApi $debitNotes;
    public readonly EquivalentDocumentAdjustmentsApi $equivalentDocumentAdjustments;
    public readonly EquivalentDocumentsApi $equivalentDocuments;
    public readonly EventsApi $events;
    public readonly GeneralApi $general;
    public readonly InvoicesApi $invoices;
    public readonly PayrollAdjustmentsApi $payrollAdjustments;
    public readonly PayrollsApi $payrolls;
    public readonly RadianEventsApi $radianEvents;
    public readonly RequestsApi $requests;
    public readonly SupportDocumentAdjustmentsApi $supportDocumentAdjustments;
    public readonly SupportDocumentsApi $supportDocuments;
    public readonly WebhookEndpointsApi $webhookEndpoints;

    /**
     * @param string|null $apiKey `tvl_test_…` o `tvl_live_…`; si falta se lee `TREVOL_API_KEY`.
     * @param string|null $apiVersion el valor de `Trevol-Version` en cada petición (por defecto `API_VERSION`).
     * @param int $maxRetries reintentos tras el primer intento (3 intentos en total por defecto). 0 los apaga.
     * @param float $timeout segundos por intento (65 por defecto, como el playground).
     * @param float $maxRetryAfter si `Retry-After` pide esperar más segundos que esto, no se espera: se lanza el error con `getRetryAfter()`.
     * @param string $baseUrl por defecto https://api.trevol.si/v1 (para un servidor simulado en las pruebas).
     * @param string|null $environment `test` o `live`: si la llave no coincide, `ConfigurationException` ANTES de llamar.
     * @param callable|null $handler el manejador de Guzzle que hace las peticiones (proxy, `MockHandler`, otro transporte).
     * @param string|null $proxy URL de un proxy (se pasa a Guzzle como opción `proxy`).
     * @param array<string, callable>|null $internos solo para pruebas: `dormir`, `azar` y `uuid`.
     */
    public function __construct(
        #[\SensitiveParameter] ?string $apiKey = null,
        ?string $apiVersion = null,
        int $maxRetries = 2,
        float $timeout = 65.0,
        float $maxRetryAfter = 60.0,
        string $baseUrl = self::BASE_URL,
        ?string $environment = null,
        ?callable $handler = null,
        ?string $proxy = null,
        ?array $internos = null,
    ) {
        $llave = $apiKey ?? (getenv('TREVOL_API_KEY') ?: '');
        if (trim($llave) === '') {
            throw new ConfigurationException('Falta la llave de la API: pásala a new Trevol(...) o define la variable de entorno TREVOL_API_KEY.');
        }
        $this->environment = Llave::entorno($llave);
        if ($environment !== null && $environment !== $this->environment) {
            throw new ConfigurationException(sprintf(
                'Pediste el entorno «%s» pero la llave es de «%s» (%s).',
                $environment,
                $this->environment,
                $this->environment === 'test' ? 'tvl_test_ = habilitación' : 'tvl_live_ = producción'
            ));
        }
        if ($maxRetries < 0) {
            throw new ConfigurationException('maxRetries no puede ser negativo.');
        }
        $this->apiVersion = $apiVersion ?? self::API_VERSION;
        $baseUrl = rtrim($baseUrl, '/');
        $ruta = parse_url($baseUrl, PHP_URL_PATH);
        $internos ??= [];
        $opciones = new Opciones(
            trim($llave),
            $this->apiVersion,
            'trevol-php/' . self::VERSION . ' (php ' . PHP_VERSION . '; +https://platform.trevol.si/docs)',
            $maxRetries,
            $maxRetryAfter,
            is_string($ruta) ? rtrim($ruta, '/') : '',
            \Closure::fromCallable($internos['dormir'] ?? static fn (float $s) => usleep((int) round($s * 1_000_000))),
            \Closure::fromCallable($internos['azar'] ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1)),
            \Closure::fromCallable($internos['uuid'] ?? static fn (): string => self::uuid4()),
        );

        $pila = HandlerStack::create($handler ?? Utils::chooseHandler());
        // Antes de `http_errors`: ve la respuesta tal cual, la convierte en nuestra excepción y reintenta.
        $pila->before('http_errors', Transporte::middleware($opciones), 'trevol');
        $config = ['handler' => $pila, 'http_errors' => false, 'timeout' => $timeout, 'connect_timeout' => min($timeout, 30.0)];
        if ($proxy !== null) {
            $config['proxy'] = $proxy;
        }
        $cliente = new Client($config);
        $generada = (new Configuration())->setHost($baseUrl)->setUserAgent($opciones->userAgent);

        $this->balance = new BalanceApi($cliente, $generada);
        $this->creditNotes = new CreditNotesApi($cliente, $generada);
        $this->debitNotes = new DebitNotesApi($cliente, $generada);
        $this->equivalentDocumentAdjustments = new EquivalentDocumentAdjustmentsApi($cliente, $generada);
        $this->equivalentDocuments = new EquivalentDocumentsApi($cliente, $generada);
        $this->events = new EventsApi($cliente, $generada);
        $this->general = new GeneralApi($cliente, $generada);
        $this->invoices = new InvoicesApi($cliente, $generada);
        $this->payrollAdjustments = new PayrollAdjustmentsApi($cliente, $generada);
        $this->payrolls = new PayrollsApi($cliente, $generada);
        $this->radianEvents = new RadianEventsApi($cliente, $generada);
        $this->requests = new RequestsApi($cliente, $generada);
        $this->supportDocumentAdjustments = new SupportDocumentAdjustmentsApi($cliente, $generada);
        $this->supportDocuments = new SupportDocumentsApi($cliente, $generada);
        $this->webhookEndpoints = new WebhookEndpointsApi($cliente, $generada);
    }

    /**
     * Recorre todas las páginas de un listado, perezosamente:
     * `foreach ($trevol->paginate(fn (?string $cursor) => $trevol->invoices->listInvoices(100, $cursor)) as $f) { … }`
     *
     * @param callable(?string): mixed $pagina
     * @return \Generator<int, mixed>
     */
    public function paginate(callable $pagina): \Generator
    {
        return Paginacion::paginar($pagina);
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    /** Un var_dump/print_r nunca debe enseñar nada sensible (la llave tampoco se guarda en el objeto). */
    public function __debugInfo(): array
    {
        return ['environment' => $this->environment, 'apiVersion' => $this->apiVersion];
    }
}
