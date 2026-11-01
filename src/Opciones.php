<?php

declare(strict_types=1);

namespace Trevol;

/** Las opciones que el transporte necesita en cada petición. @internal */
final class Opciones
{
    /**
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
    ) {
    }

    /** La llave nunca sale en un var_dump, un log ni un traceback. */
    public function __debugInfo(): array
    {
        return ['apiVersion' => $this->apiVersion, 'maxReintentos' => $this->maxReintentos];
    }
}
