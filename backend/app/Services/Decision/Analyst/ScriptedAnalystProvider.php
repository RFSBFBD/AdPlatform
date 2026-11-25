<?php

declare(strict_types=1);

namespace App\Services\Decision\Analyst;

/** Local deterministic stand-in for tests. Never calls a network and is not a model. */
final class ScriptedAnalystProvider implements AnalystProvider
{
    /** @var list<array> */
    public array $requests = [];

    public function __construct(private readonly ?array $reply)
    {
    }

    public function isExternal(): bool
    {
        return false;
    }

    public function claims(array $request): ?array
    {
        $this->requests[] = $request;

        return $this->reply;
    }
}
