<?php

declare(strict_types=1);

namespace Trevol;

/** Las opciones que el transporte necesita en cada petición. @internal */
final class Opciones
{
    /**
     * @param 'api'|'account' $tipoLlave `api` (tvl_) o `account` (tva_, la llave de administración)
     * @param 'test'|'live' $entorno el del prefijo de la llave (va en `Trevol-Environment` con una tva_)
     * @param string|null $empresa el `com_…` que va en `Trevol-Company` con una tva_ (null: sin empresa)
     * @param \Closure(float): void $dormir
     * @param \Closure(): float $azar
     * @param \Closure(): string $uuid
     */
    public function __construct(
        #[\SensitiveParameter] public readonly string $llave,
        public readonly string $apiVersion,
        public readonly string $userAgent,
        public readonly int $maxReintentos,
        public readonly float $maxRetryAfter,
        public readonly string $rutaBase,
        public readonly \Closure $dormir,
        public readonly \Closure $azar,
        public readonly \Closure $uuid,
        public readonly string $tipoLlave = 'api',
        public readonly string $entorno = 'test',
        public readonly ?string $empresa = null,
    ) {
    }

    /** Las mismas opciones con otra empresa (para `withCompany`). */
    public function conEmpresa(string $empresa): self
    {
        return new self(
            $this->llave,
            $this->apiVersion,
            $this->userAgent,
            $this->maxReintentos,
            $this->maxRetryAfter,
            $this->rutaBase,
            $this->dormir,
            $this->azar,
            $this->uuid,
            $this->tipoLlave,
            $this->entorno,
            $empresa,
        );
    }

    /** La llave nunca sale en un var_dump, un log ni un traceback. */
    public function __debugInfo(): array
    {
        return [
            'apiVersion' => $this->apiVersion,
            'maxReintentos' => $this->maxReintentos,
            'tipoLlave' => $this->tipoLlave,
            'entorno' => $this->entorno,
            'empresa' => $this->empresa,
        ];
    }
}
