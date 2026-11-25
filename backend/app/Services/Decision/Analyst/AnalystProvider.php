<?php

declare(strict_types=1);

namespace App\Services\Decision\Analyst;

/**
 * Boundary for any language model. A provider only SELECTS evidence by pointer and states a numeric relation
 * between two pointers. It never writes text or numbers that reach the user.
 */
interface AnalystProvider
{
    /** True when a call leaves this process (owner evidence would be disclosed to the owner's configured model). */
    public function isExternal(): bool;

    /**
     * @param  array{question:string,evidence:list<array{pointer:string,value:mixed}>,instructions:string}  $request
     * @return array|null decoded reply, or null when the provider produced nothing usable
     */
    public function claims(array $request): ?array;
}
