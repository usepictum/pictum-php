<?php

declare(strict_types=1);

namespace Pictum;

enum AvatarVariant: string
{
    case Identicon = 'identicon';
    case Gradient = 'gradient';
    case Monogram = 'monogram';
    case Portrait = 'portrait';
}
