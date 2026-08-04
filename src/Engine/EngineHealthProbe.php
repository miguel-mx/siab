<?php

namespace App\Engine;

/**
 * "Is the engine answering right now?" — the one thing EngineLauncher needs from
 * the engine client, split out so starting the service can be tested without an
 * HTTP stack behind it.
 */
interface EngineHealthProbe
{
    public function isHealthy(): bool;
}
