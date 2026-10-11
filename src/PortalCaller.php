<?php

namespace ArchMcp;

/**
 * Anything that can make one server-to-server call to a Portal endpoint on
 * behalf of the person driving the session: PortalAssetsClient is the real
 * one. The installer (BootstrapInstall) depends on this shape only, so its
 * tests can stand in a fake Portal.
 */
interface PortalCaller
{
    /**
     * @param array<string, mixed> $fields extra JSON fields for the request body
     *
     * @return array<string, mixed> Portal's decoded reply (always has `success`; failures carry `code` and `error`)
     */
    public function call(string $action, string $slug, array $fields = []): array;
}
