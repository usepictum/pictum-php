<?php

declare(strict_types=1);

namespace Pictum;

use BackedEnum;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use RangeException;

final readonly class Pictum
{
    public const DEFAULT_BASE_URL = 'https://pictum.dev/v1';

    private const MAX_PLACEHOLDER_PIXELS = 4_194_304;

    private string $baseUrl;

    private ClientInterface $httpClient;

    private RequestFactoryInterface $requestFactory;

    public function __construct(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory
            ?? ($this->httpClient instanceof RequestFactoryInterface
                ? $this->httpClient
                : Psr17FactoryDiscovery::findRequestFactory());
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
    }

    public function icon(string $name, ?string $baseUrl = null): PictumAsset
    {
        $parts = explode(':', $name);
        $collection = $parts[0];
        $iconName = $parts[1] ?? '';

        if (
            count($parts) !== 2
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $collection) !== 1
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $iconName) !== 1
        ) {
            throw new InvalidArgumentException(
                'Icon name must use lowercase kebab-case collection:name syntax.',
            );
        }

        $url = $this->resolveBaseUrl($baseUrl)."/icons/{$collection}:{$iconName}.svg";

        return $this->asset($url, $url);
    }

    public function avatar(
        string $seed,
        AvatarVariant|string $variant = AvatarVariant::Monogram,
        AvatarFormat|string|null $format = null,
        ?string $baseUrl = null,
        AvatarGender|string|null $gender = null,
        ?int $size = null,
    ): PictumAsset {
        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._~@+-]{0,126}[A-Za-z0-9])?$/', $seed) !== 1) {
            throw new InvalidArgumentException(
                'Avatar seed must be 1-128 URL-safe ASCII characters and start and end with a letter or number.',
            );
        }

        $variant = $variant instanceof AvatarVariant ? $variant->value : $variant;
        self::assertOneOf($variant, AvatarVariant::cases(), 'Avatar variant');

        $format = $format instanceof AvatarFormat ? $format->value : $format;
        $format ??= $variant === AvatarVariant::Portrait->value
            ? AvatarFormat::Webp->value
            : AvatarFormat::Svg->value;
        self::assertOneOf($format, AvatarFormat::cases(), 'Avatar format');

        $gender = $gender instanceof AvatarGender ? $gender->value : $gender;
        $portrait = $variant === AvatarVariant::Portrait->value;

        if (! $portrait && $gender !== null) {
            throw new InvalidArgumentException(
                'Avatar gender is only available for portrait avatars.',
            );
        }

        if ($portrait && $format === AvatarFormat::Svg->value) {
            throw new InvalidArgumentException('Portrait avatars do not support SVG format.');
        }

        if ($gender !== null) {
            self::assertOneOf($gender, AvatarGender::cases(), 'Avatar gender');
        }

        if ($format === AvatarFormat::Svg->value && $size !== null) {
            throw new InvalidArgumentException('Avatar size is only available for raster formats.');
        }

        if ($size !== null) {
            self::assertIntegerInRange($size, 16, 1024, 'Avatar size');
        }

        $query = ['seed' => $seed];

        if ($variant !== AvatarVariant::Monogram->value) {
            $query['variant'] = $variant;
        }

        $svgQueryString = http_build_query($query, '', '&', PHP_QUERY_RFC1738);

        if ($gender !== null && $gender !== AvatarGender::Any->value) {
            $query['gender'] = $gender;
        }

        if ($size !== null) {
            $query['size'] = $size;
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC1738);
        $path = $this->resolveBaseUrl($baseUrl).'/avatar';

        return $this->asset(
            "{$path}.{$format}?{$queryString}",
            $portrait ? null : "{$path}.svg?{$svgQueryString}",
        );
    }

    public function qrCode(
        string $value,
        QrCodeFormat|string $format = QrCodeFormat::Svg,
        ?string $baseUrl = null,
        ?bool $quietZone = null,
        ?string $foreground = null,
        ?string $background = null,
    ): PictumAsset {
        $format = $format instanceof QrCodeFormat ? $format->value : $format;
        self::assertOneOf($format, QrCodeFormat::cases(), 'QR code format');

        if ($value === '' || strlen($value) > 512 || preg_match('//u', $value) !== 1) {
            throw new RangeException('QR code value must contain 1-512 UTF-8 bytes.');
        }

        if ($foreground !== null && preg_match('/^#[0-9A-Fa-f]{6}(?:[0-9A-Fa-f]{2})?\z/', $foreground) !== 1) {
            throw new InvalidArgumentException('QR code foreground must use #rrggbb or #rrggbbaa syntax.');
        }

        if ($background !== null && preg_match('/^#[0-9A-Fa-f]{6}(?:[0-9A-Fa-f]{2})?\z/', $background) !== 1) {
            throw new InvalidArgumentException('QR code background must use #rrggbb or #rrggbbaa syntax.');
        }

        $query = ['data' => base64_encode($value)];

        if ($quietZone !== null) {
            $query['quiet_zone'] = $quietZone ? 1 : 0;
        }

        if ($foreground !== null) {
            $query['foreground'] = $foreground;
        }

        if ($background !== null) {
            $query['background'] = $background;
        }

        $queryString = http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC1738,
        );
        $path = $this->resolveBaseUrl($baseUrl).'/qrcode';

        return $this->asset(
            "{$path}.{$format}?{$queryString}",
            "{$path}.svg?{$queryString}",
        );
    }

    public function placeholder(
        ?int $size = null,
        ?int $width = null,
        ?int $height = null,
        PlaceholderFormat|string $format = PlaceholderFormat::Svg,
        ?int $density = null,
        ?string $background = null,
        ?string $color = null,
        ?string $text = null,
        ?string $baseUrl = null,
    ): PictumAsset {
        $format = $format instanceof PlaceholderFormat ? $format->value : $format;
        self::assertOneOf($format, PlaceholderFormat::cases(), 'Placeholder format');

        if ($size !== null) {
            if ($width !== null || $height !== null) {
                throw new InvalidArgumentException(
                    'Placeholder accepts either size or width and height, not both.',
                );
            }

            self::assertIntegerInRange($size, 16, 2048, 'Placeholder size');
            $width = $size;
            $height = $size;
            $dimensionQuery = ['size' => $size];
        } else {
            if ($width === null || $height === null) {
                throw new InvalidArgumentException('Placeholder requires size or both width and height.');
            }

            self::assertIntegerInRange($width, 16, 4096, 'Placeholder width');
            self::assertIntegerInRange($height, 16, 4096, 'Placeholder height');

            if ($width * $height > self::MAX_PLACEHOLDER_PIXELS) {
                throw new RangeException('Placeholder dimensions exceed the API pixel limit.');
            }

            $dimensionQuery = ['width' => $width, 'height' => $height];
        }

        if ($background !== null && preg_match('/^#[0-9A-Fa-f]{6}(?:[0-9A-Fa-f]{2})?\z/', $background) !== 1) {
            throw new InvalidArgumentException('Placeholder background must use #rrggbb or #rrggbbaa syntax.');
        }

        if ($color !== null && preg_match('/^#[0-9A-Fa-f]{6}(?:[0-9A-Fa-f]{2})?\z/', $color) !== 1) {
            throw new InvalidArgumentException('Placeholder color must use #rrggbb or #rrggbbaa syntax.');
        }

        if ($text !== null) {
            $textLength = preg_match_all('/./us', $text);

            if ($textLength === false) {
                throw new InvalidArgumentException('Placeholder text must be valid UTF-8.');
            }

            if ($textLength > 64) {
                throw new RangeException('Placeholder text cannot exceed 64 characters.');
            }
        }

        if ($density !== null) {
            if ($format === PlaceholderFormat::Svg->value) {
                throw new InvalidArgumentException('Placeholder density is not available for SVG.');
            }

            if ($density !== 2 && $density !== 3) {
                throw new RangeException('Placeholder density must be 2 or 3.');
            }

            $renderedWidth = $width * $density;
            $renderedHeight = $height * $density;

            if (
                $renderedWidth > 4096
                || $renderedHeight > 4096
                || $renderedWidth * $renderedHeight > self::MAX_PLACEHOLDER_PIXELS
            ) {
                throw new RangeException(
                    'Rendered placeholder dimensions exceed the API pixel limits.',
                );
            }
        }

        $query = $dimensionQuery;

        if ($density !== null) {
            $query['density'] = $density;
        }

        $svgQuery = $dimensionQuery;

        if ($background !== null) {
            $query['background'] = $background;
            $svgQuery['background'] = $background;
        }
        if ($color !== null) {
            $query['color'] = $color;
            $svgQuery['color'] = $color;
        }
        if ($text !== null) {
            $query['text'] = $text;
            $svgQuery['text'] = $text;
        }

        $queryString = str_replace('%2A', '*', http_build_query($query, '', '&', PHP_QUERY_RFC1738));
        $svgQueryString = str_replace('%2A', '*', http_build_query($svgQuery, '', '&', PHP_QUERY_RFC1738));
        $path = $this->resolveBaseUrl($baseUrl).'/placeholder';

        return $this->asset(
            "{$path}.{$format}?{$queryString}",
            "{$path}.svg?{$svgQueryString}",
        );
    }

    private function asset(string $url, ?string $svgUrl): PictumAsset
    {
        return new PictumAsset(
            $url,
            $svgUrl,
            $this->httpClient,
            $this->requestFactory,
        );
    }

    private function resolveBaseUrl(?string $baseUrl): string
    {
        return $baseUrl === null ? $this->baseUrl : self::normalizeBaseUrl($baseUrl);
    }

    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        $parts = parse_url($baseUrl);

        if (
            $parts === false
            || ! isset($parts['scheme'], $parts['host'])
            || preg_match('/[\x00-\x20\x7F]/', $baseUrl) === 1
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException('Pictum baseUrl must be an absolute URL.');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('Pictum baseUrl must use HTTP or HTTPS.');
        }

        if (
            isset($parts['user'])
            || isset($parts['pass'])
            || str_contains($baseUrl, '?')
            || str_contains($baseUrl, '#')
        ) {
            throw new InvalidArgumentException(
                'Pictum baseUrl cannot contain credentials, a query, or a fragment.',
            );
        }

        $port = $parts['port'] ?? null;
        $isDefaultPort = ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443);
        $authority = strtolower($parts['host']);

        if ($port !== null && ! $isDefaultPort) {
            $authority .= ':'.$port;
        }

        return rtrim($scheme.'://'.$authority.($parts['path'] ?? ''), '/');
    }

    /**
     * @param  list<BackedEnum>  $allowed
     */
    private static function assertOneOf(string $value, array $allowed, string $label): void
    {
        $values = array_map(
            static fn (BackedEnum $item): string => (string) $item->value,
            $allowed,
        );

        if (! in_array($value, $values, true)) {
            throw new InvalidArgumentException(
                "{$label} must be one of: ".implode(', ', $values).'.',
            );
        }
    }

    private static function assertIntegerInRange(
        int $value,
        int $minimum,
        int $maximum,
        string $label,
    ): void {
        if ($value < $minimum || $value > $maximum) {
            throw new RangeException(
                "{$label} must be an integer between {$minimum} and {$maximum}.",
            );
        }
    }
}
