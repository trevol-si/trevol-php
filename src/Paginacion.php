<?php

declare(strict_types=1);

namespace Trevol;

/** Paginación con generadores (docs/api/43 §2.6.7): la API pagina por cursor (`starting_after`). */
final class Paginacion
{
    /**
     * Recorre todas las páginas de un listado, perezosamente.
     *
     * `$pagina(?string $cursor)` pide UNA página (`starting_after = $cursor`; null la primera) y devuelve el sobre de lista
     * generado (`getData()`, `getHasMore()`, `getNextCursor()`). Sigue con `next_cursor` mientras `has_more`; si la página no lo
     * trae, usa el `id` del último elemento (así lo arma la API). Se detiene si el cursor no avanza.
     *
     * @param callable(?string): mixed $pagina
     * @return \Generator<int, mixed>
     */
    public static function paginar(callable $pagina): \Generator
    {
        $cursor = null;
        while (true) {
            $p = $pagina($cursor);
            $datos = self::datos($p);
            foreach ($datos as $d) {
                yield $d;
            }
            if (!self::tomar($p, 'getHasMore', 'has_more') || $datos === []) {
                return;
            }
            $siguiente = self::tomar($p, 'getNextCursor', 'next_cursor');
            if (!is_string($siguiente) || $siguiente === '') {
                $ultimo = end($datos);
                $siguiente = is_object($ultimo) && method_exists($ultimo, 'getId') ? $ultimo->getId() : ($ultimo['id'] ?? null);
            }
            if (!is_string($siguiente) || $siguiente === '' || $siguiente === $cursor) {
                return;
            }
            $cursor = $siguiente;
        }
    }

    /** @return list<mixed> */
    private static function datos(mixed $p): array
    {
        $d = self::tomar($p, 'getData', 'data');

        return is_array($d) ? array_values($d) : [];
    }

    private static function tomar(mixed $p, string $metodo, string $clave): mixed
    {
        if (is_object($p) && method_exists($p, $metodo)) {
            return $p->$metodo();
        }

        return is_array($p) ? ($p[$clave] ?? null) : null;
    }
}
