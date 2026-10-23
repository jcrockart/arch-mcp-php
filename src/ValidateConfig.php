<?php

namespace ArchMcp;

require_once __DIR__.'/ValidateContext.php';
require_once __DIR__.'/ValidateFiles.php';
require_once __DIR__.'/PyValue.php';

/**
 * Section 0 of the metadata validator: metadata/project.config.json, which
 * every project must have (a faithful port of validate.py's Project Config
 * section, including the 2026-09-25 per-environment hosting and `sqlite`).
 */
final class ValidateConfig
{
    private const CONFIG_REQUIRED = ['project', 'git', 'deployment', 'capabilities'];
    private const GIT_REQUIRED = ['remote', 'default_branch'];
    private const HOSTING_REQUIRED = ['provider', 'server_host', 'account_username', 'domain', 'document_root', 'deploy_path'];
    private const ENVIRONMENTS = ['staging', 'production'];
    private const DEPLOYMENT_MECHANISMS = ['ssh-git-pull', 'sftp-direct-sync', 'provider-api'];
    private const RUNTIMES = ['static-html', 'php', 'node'];
    private const DATABASES = ['mysql', 'sqlite', 'none'];

    public static function run(ValidateContext $ctx, string $metaDir): void
    {
        $path = $metaDir.'/project.config.json';
        if (!file_exists($path)) {
            $ctx->fail(
                "{$path}: missing. Every ARCH-bootstrapped project requires a project.config.json "
                .'declaring its git remote, hosting target, deployment mechanism, and capability selection — '
                ."this is the installable-materials proposal's one mandatory file."
            );

            return;
        }

        $ctx->guard($path, static function () use ($ctx, $path): void {
            $cfg = ValidateFiles::json($ctx, $path);
            if (null === $cfg) {
                return;
            }
            if (!$cfg instanceof \stdClass) {
                throw new ValidateCrash('the top-level value must be an object');
            }

            $missing = array_diff(self::CONFIG_REQUIRED, PyValue::keys($cfg));
            if ([] !== $missing) {
                $ctx->fail("{$path}: missing required top-level field(s): ".PyValue::pyList($missing));
            }

            $git = self::object($cfg, 'git');
            $missingGit = array_diff(self::GIT_REQUIRED, PyValue::keys($git));
            if ([] !== $missingGit) {
                $ctx->fail("{$path}: git config missing field(s): ".PyValue::pyList($missingGit));
            }

            $hasHosting = PyValue::has($cfg, 'hosting');
            $hasEnvironments = PyValue::has($cfg, 'environments');
            if ($hasHosting && $hasEnvironments) {
                $ctx->fail(
                    "{$path}: declare either 'environments' (per environment) or the legacy "
                    ."single 'hosting' block, not both"
                );
            } elseif (!$hasHosting && !$hasEnvironments) {
                $ctx->fail(
                    "{$path}: missing hosting — declare 'environments' (at least 'production', "
                    ."optionally 'staging') or the legacy single 'hosting' block"
                );
            } elseif ($hasHosting) {
                $hosting = self::object($cfg, 'hosting');
                $missingHosting = array_diff(self::HOSTING_REQUIRED, PyValue::keys($hosting));
                if ([] !== $missingHosting) {
                    $ctx->fail("{$path}: hosting config missing field(s): ".PyValue::pyList($missingHosting));
                }
            } else {
                self::environments($ctx, $path, PyValue::get($cfg, 'environments'));
            }

            $deployment = self::object($cfg, 'deployment');
            $mechanism = PyValue::get($deployment, 'mechanism');
            if (!PyValue::inList($mechanism, self::DEPLOYMENT_MECHANISMS)) {
                $ctx->fail(
                    "{$path}: deployment.mechanism '".PyValue::str($mechanism)."' not in allowed set "
                    .PyValue::pyList(self::DEPLOYMENT_MECHANISMS)
                );
            }

            $capabilities = self::object($cfg, 'capabilities');
            $runtime = PyValue::get($capabilities, 'runtime');
            if (!PyValue::inList($runtime, self::RUNTIMES)) {
                $ctx->fail("{$path}: capabilities.runtime '".PyValue::str($runtime)."' not in allowed set ".PyValue::pyList(self::RUNTIMES));
            }
            $database = PyValue::get($capabilities, 'database');
            if (!PyValue::inList($database, self::DATABASES)) {
                $ctx->fail("{$path}: capabilities.database '".PyValue::str($database)."' not in allowed set ".PyValue::pyList(self::DATABASES));
            }
        });
    }

    private static function environments(ValidateContext $ctx, string $path, mixed $environments): void
    {
        if (!$environments instanceof \stdClass || !PyValue::has($environments, 'production')) {
            $ctx->fail("{$path}: 'environments' must be an object with at least a 'production' entry");

            return;
        }
        $names = PyValue::keys($environments);
        $unknown = array_diff($names, self::ENVIRONMENTS);
        if ([] !== $unknown) {
            $ctx->fail("{$path}: unknown environment(s) ".PyValue::pyList($unknown).' — allowed: '.PyValue::pyList(self::ENVIRONMENTS));
        }
        $known = array_intersect($names, self::ENVIRONMENTS);
        sort($known, \SORT_STRING);
        foreach ($known as $envName) {
            $env = PyValue::get($environments, $envName);
            if (!$env instanceof \stdClass) {
                $ctx->fail("{$path}: environments.{$envName} must be an object");
                continue;
            }
            $missing = array_diff(self::HOSTING_REQUIRED, PyValue::keys($env));
            if ([] !== $missing) {
                $ctx->fail("{$path}: environments.{$envName} missing field(s): ".PyValue::pyList($missing));
            }
        }
    }

    /** A member that must be an object; an absent one counts as {}. Anything else cannot be read. */
    private static function object(\stdClass $cfg, string $key): \stdClass
    {
        $value = PyValue::get($cfg, $key, new \stdClass());
        if (!$value instanceof \stdClass) {
            throw new ValidateCrash("'{$key}' must be an object");
        }

        return $value;
    }
}
