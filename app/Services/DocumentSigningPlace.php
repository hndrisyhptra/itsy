<?php

namespace App\Services;

/** Reuse the already-authorized related PP; never query or rewrite historical documents. */
final class DocumentSigningPlace
{
    public static function fromProject(array $project): string
    {
        return is_string($project['branch'] ?? null) ? trim($project['branch']) : '';
    }
}
