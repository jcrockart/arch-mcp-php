<?php

namespace ArchMcp;

require_once __DIR__.'/ValidateContext.php';
require_once __DIR__.'/ValidateConfig.php';
require_once __DIR__.'/ValidateEntities.php';
require_once __DIR__.'/ValidateDomain.php';
require_once __DIR__.'/ValidateWorkflows.php';
require_once __DIR__.'/ValidateIntegrations.php';

/**
 * The ARCH metadata validator: the commit gate's "validate" step, built into
 * this server since the fold-in of arch-core. It applies exactly the rules of
 * arch-core's validate.py (as of 2026-10-23) to a project's metadata/ folder:
 * the project config, entities, domain models, workflows and integrations.
 *
 * One entry point; each section lives in its own Validate* file. The result
 * carries the same report text validate.py printed, so a commit failure reads
 * as it always has.
 *
 * Known difference: a metadata file that holds a JSON value PHP cannot decode
 * but Python could (NaN, Infinity, -Infinity) now fails as invalid JSON.
 */
final class MetadataValidator
{
    /**
     * @param string $projectRoot the project's checkout; its metadata/ folder is what is checked
     *
     * @return array{ok: bool, errors: list<string>, output: string}
     */
    public static function run(string $projectRoot): array
    {
        $meta = rtrim($projectRoot, '/').'/metadata';
        $ctx = new ValidateContext();

        ValidateConfig::run($ctx, $meta);
        ValidateEntities::run($ctx, $meta);
        ValidateDomain::run($ctx, $meta);
        ValidateWorkflows::run($ctx, $meta);
        ValidateIntegrations::run($ctx, $meta);

        $out = "ARCH metadata validation — {$meta}\n\n";
        if ([] !== $ctx->errors) {
            $out .= 'COMMIT WOULD FAIL — '.\count($ctx->errors)." error(s):\n\n";
            foreach ($ctx->errors as $e) {
                $out .= "  ✗ {$e}\n";
            }
            $out .= "\nBranch remains untouched. main is unaffected. (constitution rule 5)\n";

            return ['ok' => false, 'errors' => $ctx->errors, 'output' => $out];
        }

        $out .= "COMMIT WOULD PASS — all metadata is structurally and semantically valid.\n";
        $out .= "Eligible for atomic merge to main.\n";

        return ['ok' => true, 'errors' => [], 'output' => $out];
    }
}
