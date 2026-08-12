<?php

declare(strict_types=1);

namespace Pictum\Exception;

use RuntimeException;

final class SvgRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $reasonPhrase,
    ) {
        $status = trim($statusCode.' '.$reasonPhrase);

        parent::__construct("Pictum SVG request failed with status {$status}.");
    }
}
