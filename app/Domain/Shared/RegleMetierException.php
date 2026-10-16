<?php

namespace App\Domain\Shared;

use RuntimeException;

/**
 * Règle de gestion non respectée. Rendue en HTTP 422 avec un message
 * destiné à l'utilisateur et un code stable exploitable par l'interface.
 */
class RegleMetierException extends RuntimeException
{
    /** @param array<string, mixed> $contexte */
    public function __construct(
        string $message,
        public readonly string $codeRegle = 'regle_metier',
        public readonly array $contexte = [],
        public readonly int $statutHttp = 422,
    ) {
        parent::__construct($message);
    }

    public static function conflit(string $message, string $code = 'conflit'): self
    {
        return new self($message, $code, [], 409);
    }
}
