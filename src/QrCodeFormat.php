<?php

declare(strict_types=1);

namespace Pictum;

enum QrCodeFormat: string
{
    case Svg = 'svg';
    case Jpg = 'jpg';
    case Png = 'png';
    case Webp = 'webp';
}
