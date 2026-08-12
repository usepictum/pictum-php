<?php

declare(strict_types=1);

namespace Pictum\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pictum\AvatarFormat;
use Pictum\AvatarGender;
use Pictum\AvatarVariant;
use Pictum\Exception\SvgRequestFailed;
use Pictum\Pictum;
use Pictum\PlaceholderFormat;
use Pictum\QrCodeFormat;
use Pictum\Tests\Support\MockHttpClient;
use RangeException;
use RuntimeException;

final class PictumTest extends TestCase
{
    public function test_it_discovers_compatible_http_implementations(): void
    {
        self::assertSame(
            'https://pictum.dev/v1/icons/devicon:react.svg',
            (new Pictum)->icon('devicon:react')->url,
        );
    }

    public function test_an_explicit_client_takes_precedence_while_the_factory_is_discovered(): void
    {
        $httpClient = new MockHttpClient(new Response(200, [], '<svg>explicit</svg>'));
        $asset = (new Pictum(httpClient: $httpClient))->icon('devicon:react');

        self::assertSame('<svg>explicit</svg>', $asset->svg());
        self::assertCount(1, $httpClient->requests);
    }

    public function test_it_builds_asset_urls_without_requesting_them(): void
    {
        $httpClient = new MockHttpClient;
        $pictum = new Pictum($httpClient, new HttpFactory);

        self::assertSame(
            'https://pictum.dev/v1/icons/devicon:react.svg',
            $pictum->icon('devicon:react')->url,
        );
        self::assertSame(
            'https://pictum.dev/v1/avatar.svg?seed=ada-lovelace',
            $pictum->avatar('ada-lovelace')->url,
        );
        self::assertSame([], $httpClient->requests);
    }

    public function test_it_builds_avatar_variants_and_formats(): void
    {
        $pictum = $this->pictum();

        self::assertSame(
            'https://pictum.dev/v1/avatar.webp?seed=ada%40example.com&variant=gradient&size=512',
            $pictum->avatar(
                'ada@example.com',
                variant: AvatarVariant::Gradient,
                format: AvatarFormat::Webp,
                size: 512,
            )->url,
        );
        self::assertSame(
            'https://pictum.dev/v1/avatar.png?seed=ada%2Blovelace&variant=identicon',
            $pictum->avatar('ada+lovelace', variant: 'identicon', format: 'png')->url,
        );
    }

    public function test_it_builds_portrait_avatars_and_rejects_svg_retrieval(): void
    {
        $httpClient = new MockHttpClient;
        $pictum = new Pictum($httpClient, new HttpFactory);
        $asset = $pictum->avatar('ada', variant: AvatarVariant::Portrait);

        self::assertSame(
            'https://pictum.dev/v1/avatar.webp?seed=ada&variant=portrait',
            $asset->url,
        );
        self::assertSame(
            'https://pictum.dev/v1/avatar.png?seed=ada&variant=portrait&gender=female&size=256',
            $pictum->avatar(
                'ada',
                variant: 'portrait',
                format: AvatarFormat::Png,
                gender: AvatarGender::Female,
                size: 256,
            )->url,
        );
        self::assertSame(
            'https://pictum.dev/v1/avatar.png?seed=ada&variant=portrait&size=256',
            $pictum->avatar(
                'ada',
                variant: AvatarVariant::Portrait,
                format: AvatarFormat::Png,
                gender: AvatarGender::Any,
                size: 256,
            )->url,
        );

        try {
            $asset->svg();
            self::fail('Portrait avatars should not support SVG retrieval.');
        } catch (LogicException $exception) {
            self::assertSame(
                'This Pictum asset does not support SVG.',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $httpClient->requests);
    }

    public function test_it_encodes_qr_code_values_as_standard_utf8_base64(): void
    {
        self::assertSame(
            'https://pictum.dev/v1/qrcode.svg?data=w6k%3D',
            $this->pictum()->qrCode('é')->url,
        );
        self::assertSame(
            'https://pictum.dev/v1/qrcode.webp?data=aHR0cHM6Ly9waWN0dW0udGVzdA%3D%3D',
            $this->pictum()->qrCode('https://pictum.test', QrCodeFormat::Webp)->url,
        );
        self::assertSame(
            'https://pictum.dev/v1/qrcode.jpg?data=aGVsbG8%3D&quiet_zone=0',
            $this->pictum()->qrCode('hello', QrCodeFormat::Jpg, quietZone: false)->url,
        );
        self::assertSame(
            'https://pictum.dev/v1/qrcode.png?data=aGVsbG8%3D&quiet_zone=1',
            $this->pictum()->qrCode('hello', format: 'png', quietZone: true)->url,
        );
    }

    public function test_qr_code_colors_have_deterministic_order_without_overriding_api_defaults(): void
    {
        self::assertSame(
            'https://pictum.dev/v1/qrcode.svg?data=aGVsbG8%3D',
            $this->pictum()->qrCode('hello')->url,
        );
        self::assertSame(
            'https://pictum.dev/v1/qrcode.svg?data=aGVsbG8%3D&foreground=%23112233',
            $this->pictum()->qrCode('hello', foreground: '#112233')->url,
        );
        self::assertSame(
            'https://pictum.dev/v1/qrcode.svg?data=aGVsbG8%3D&background=%23ffffff',
            $this->pictum()->qrCode('hello', background: '#ffffff')->url,
        );
        self::assertSame(
            'https://pictum.dev/v1/qrcode.png?data=aGVsbG8%3D&quiet_zone=1&foreground=%23AABBCCDD&background=%23112233',
            $this->pictum()->qrCode(
                'hello',
                format: QrCodeFormat::Png,
                quietZone: true,
                foreground: '#AABBCCDD',
                background: '#112233',
            )->url,
        );
    }

    public function test_it_builds_placeholder_urls_and_canonical_svg_requests(): void
    {
        $httpClient = new MockHttpClient(new Response(200, [], '<svg viewBox="0 0 320 320"></svg>'));
        $pictum = new Pictum($httpClient, new HttpFactory);
        $asset = $pictum->placeholder(
            size: 320,
            format: PlaceholderFormat::Webp,
            density: 2,
            background: '#ffffff',
            text: 'Coming soon',
        );

        self::assertSame(
            'https://pictum.dev/v1/placeholder.webp?size=320&density=2&background=%23ffffff&text=Coming+soon',
            $asset->url,
        );
        self::assertStringContainsString('<svg', $asset->svg());
        self::assertCount(1, $httpClient->requests);
        self::assertSame(
            'https://pictum.dev/v1/placeholder.svg?size=320&background=%23ffffff&text=Coming+soon',
            (string) $httpClient->request(0)->getUri(),
        );
        self::assertSame('image/svg+xml', $httpClient->request(0)->getHeaderLine('Accept'));
    }

    public function test_placeholder_query_parameters_have_deterministic_order(): void
    {
        self::assertSame(
            'https://pictum.dev/v1/placeholder.jpg?width=640&height=360&background=%23AABBCC80&color=%2311223344&text=A+%7E+*+B',
            $this->pictum()->placeholder(
                width: 640,
                height: 360,
                format: 'jpg',
                background: '#AABBCC80',
                color: '#11223344',
                text: 'A ~ * B',
            )->url,
        );
    }

    public function test_avatar_and_qr_code_fetch_their_canonical_svg_urls(): void
    {
        $httpClient = new MockHttpClient(
            new Response(200, [], '<svg>avatar</svg>'),
            new Response(200, [], '<svg>qr-code</svg>'),
        );
        $pictum = new Pictum($httpClient, new HttpFactory);

        self::assertSame(
            '<svg>avatar</svg>',
            $pictum->avatar(
                'ada',
                variant: AvatarVariant::Gradient,
                format: 'webp',
                size: 256,
            )->svg(),
        );
        self::assertSame(
            '<svg>qr-code</svg>',
            $pictum->qrCode(
                'é',
                format: 'jpg',
                quietZone: false,
                foreground: '#10203040',
                background: '#A0B0C0D0',
            )->svg(),
        );
        self::assertSame(
            'https://pictum.dev/v1/avatar.svg?seed=ada&variant=gradient',
            (string) $httpClient->request(0)->getUri(),
        );
        self::assertSame(
            'https://pictum.dev/v1/qrcode.svg?data=w6k%3D&quiet_zone=0&foreground=%2310203040&background=%23A0B0C0D0',
            (string) $httpClient->request(1)->getUri(),
        );
    }

    public function test_released_positional_arguments_remain_compatible(): void
    {
        $pictum = $this->pictum();

        self::assertSame(
            'https://assets.example.com/v1/avatar.webp?seed=ada&variant=gradient',
            $pictum->avatar(
                'ada',
                AvatarVariant::Gradient,
                AvatarFormat::Webp,
                'https://assets.example.com/v1',
            )->url,
        );
        self::assertSame(
            'https://assets.example.com/v1/qrcode.png?data=aGVsbG8%3D&quiet_zone=0',
            $pictum->qrCode(
                'hello',
                QrCodeFormat::Png,
                'https://assets.example.com/v1',
                false,
            )->url,
        );
    }

    public function test_it_normalizes_configured_and_per_asset_base_urls(): void
    {
        $pictum = $this->pictum('HTTPS://STAGING.EXAMPLE.COM:443/pictum/v1///');

        self::assertSame(
            'https://staging.example.com/pictum/v1/icons/lucide:sparkles.svg',
            $pictum->icon('lucide:sparkles')->url,
        );
        self::assertSame(
            'https://preview.example.com/v1/avatar.webp?seed=ada',
            $pictum->avatar(
                'ada',
                variant: AvatarVariant::Monogram,
                format: AvatarFormat::Webp,
                baseUrl: 'https://preview.example.com/v1/',
            )->url,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidBaseUrls(): iterable
    {
        yield 'relative' => ['/v1', 'Pictum baseUrl must be an absolute URL.'];
        yield 'missing host' => ['https:///v1', 'Pictum baseUrl must be an absolute URL.'];
        yield 'malformed host' => ['https://example.com\\evil/v1', 'Pictum baseUrl must be an absolute URL.'];
        yield 'encoded host' => ['https://%65xample.com/v1', 'Pictum baseUrl must be an absolute URL.'];
        yield 'unsupported scheme' => ['ftp://pictum.test/v1', 'Pictum baseUrl must use HTTP or HTTPS.'];
        yield 'credentials' => [
            'https://user:secret@pictum.test/v1',
            'Pictum baseUrl cannot contain credentials, a query, or a fragment.',
        ];
        yield 'query' => [
            'https://pictum.test/v1?token=secret',
            'Pictum baseUrl cannot contain credentials, a query, or a fragment.',
        ];
        yield 'empty query' => [
            'https://pictum.test/v1?',
            'Pictum baseUrl cannot contain credentials, a query, or a fragment.',
        ];
        yield 'fragment' => [
            'https://pictum.test/v1#assets',
            'Pictum baseUrl cannot contain credentials, a query, or a fragment.',
        ];
    }

    #[DataProvider('invalidBaseUrls')]
    public function test_it_rejects_invalid_base_urls(string $baseUrl, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->pictum($baseUrl);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIconNames(): iterable
    {
        yield 'missing collection' => ['react'];
        yield 'uppercase' => ['devicon:React'];
        yield 'empty icon' => ['devicon:'];
        yield 'extra separator' => ['devicon:react:filled'];
        yield 'leading hyphen' => ['-devicon:react'];
        yield 'underscore' => ['dev_icon:react'];
    }

    #[DataProvider('invalidIconNames')]
    public function test_it_rejects_invalid_icon_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Icon name must use lowercase kebab-case collection:name syntax.');

        $this->pictum()->icon($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAvatarSeeds(): iterable
    {
        yield 'empty' => [''];
        yield 'leading punctuation' => ['-ada'];
        yield 'trailing punctuation' => ['ada-'];
        yield 'space' => ['ada lovelace'];
        yield 'non-ASCII' => ['é'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[DataProvider('invalidAvatarSeeds')]
    public function test_it_rejects_invalid_avatar_seeds(string $seed): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Avatar seed must be 1-128 URL-safe ASCII characters');

        $this->pictum()->avatar($seed);
    }

    public function test_it_rejects_invalid_avatar_options(): void
    {
        $invalidOptions = [
            [
                'options' => ['variant' => 'realistic'],
                'message' => 'Avatar variant must be one of: identicon, gradient, monogram, portrait.',
            ],
            [
                'options' => ['format' => 'gif'],
                'message' => 'Avatar format must be one of: svg, jpg, png, webp.',
            ],
            [
                'options' => ['variant' => 'gradient', 'gender' => 'female'],
                'message' => 'Avatar gender is only available for portrait avatars.',
            ],
            [
                'options' => ['variant' => 'portrait', 'format' => 'svg'],
                'message' => 'Portrait avatars do not support SVG format.',
            ],
            [
                'options' => ['variant' => 'portrait', 'gender' => 'unknown'],
                'message' => 'Avatar gender must be one of: male, female, any.',
            ],
            [
                'options' => ['size' => 256],
                'message' => 'Avatar size is only available for raster formats.',
            ],
        ];

        foreach ($invalidOptions as $invalid) {
            try {
                $this->pictum()->avatar('ada', ...$invalid['options']);
                self::fail('Invalid avatar options should throw.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame($invalid['message'], $exception->getMessage());
            }
        }
    }

    public function test_it_rejects_avatar_sizes_outside_the_supported_range(): void
    {
        foreach ([15, 1025] as $size) {
            try {
                $this->pictum()->avatar(
                    'ada',
                    variant: AvatarVariant::Portrait,
                    size: $size,
                );
                self::fail('An invalid avatar size should throw.');
            } catch (RangeException $exception) {
                self::assertSame(
                    'Avatar size must be an integer between 16 and 1024.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_qr_code_values_must_be_valid_utf8_within_the_byte_limit(): void
    {
        self::assertStringContainsString('qrcode.svg', $this->pictum()->qrCode(str_repeat('a', 512))->url);

        foreach (['', str_repeat('a', 513), "\xFF"] as $value) {
            try {
                $this->pictum()->qrCode($value);
                self::fail('An invalid QR code value should throw.');
            } catch (RangeException $exception) {
                self::assertSame(
                    'QR code value must contain 1-512 UTF-8 bytes.',
                    $exception->getMessage(),
                );
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('QR code format must be one of: svg, jpg, png, webp.');

        $this->pictum()->qrCode('value', 'gif');
    }

    /**
     * @return iterable<string, array{array{foreground?: string, background?: string}, string}>
     */
    public static function invalidQrCodeColors(): iterable
    {
        yield 'foreground too short' => [['foreground' => '#12345'], 'QR code foreground must use #rrggbb or #rrggbbaa syntax.'];
        yield 'foreground invalid length' => [['foreground' => '#1234567'], 'QR code foreground must use #rrggbb or #rrggbbaa syntax.'];
        yield 'foreground too long' => [['foreground' => '#123456789'], 'QR code foreground must use #rrggbb or #rrggbbaa syntax.'];
        yield 'foreground missing hash' => [['foreground' => '12345678'], 'QR code foreground must use #rrggbb or #rrggbbaa syntax.'];
        yield 'foreground non-hex' => [['foreground' => '#12345g'], 'QR code foreground must use #rrggbb or #rrggbbaa syntax.'];
        yield 'foreground newline' => [['foreground' => "#12345678\n"], 'QR code foreground must use #rrggbb or #rrggbbaa syntax.'];
        yield 'background too short' => [['background' => '#12345'], 'QR code background must use #rrggbb or #rrggbbaa syntax.'];
        yield 'background invalid length' => [['background' => '#1234567'], 'QR code background must use #rrggbb or #rrggbbaa syntax.'];
        yield 'background too long' => [['background' => '#123456789'], 'QR code background must use #rrggbb or #rrggbbaa syntax.'];
        yield 'background missing hash' => [['background' => '12345678'], 'QR code background must use #rrggbb or #rrggbbaa syntax.'];
        yield 'background non-hex' => [['background' => '#12345g'], 'QR code background must use #rrggbb or #rrggbbaa syntax.'];
        yield 'background newline' => [['background' => "#12345678\n"], 'QR code background must use #rrggbb or #rrggbbaa syntax.'];
    }

    /**
     * @param  array{foreground?: string, background?: string}  $options
     */
    #[DataProvider('invalidQrCodeColors')]
    public function test_qr_code_colors_must_use_six_or_eight_digit_hex_syntax(array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->pictum()->qrCode('value', ...$options);
    }

    public function test_placeholder_requires_one_valid_dimension_representation(): void
    {
        $pictum = $this->pictum();

        self::assertSame(
            'https://pictum.dev/v1/placeholder.svg?size=16',
            $pictum->placeholder(size: 16)->url,
        );
        self::assertSame(
            'https://pictum.dev/v1/placeholder.svg?width=4096&height=1024',
            $pictum->placeholder(width: 4096, height: 1024)->url,
        );

        $invalidOptions = [
            ['options' => ['size' => 320, 'width' => 320, 'height' => 320], 'message' => 'either size or width and height'],
            ['options' => [], 'message' => 'requires size or both width and height'],
            ['options' => ['width' => 320], 'message' => 'requires size or both width and height'],
            ['options' => ['size' => 15], 'message' => 'integer between 16 and 2048'],
            ['options' => ['width' => 4096, 'height' => 1025], 'message' => 'dimensions exceed the API pixel limit'],
        ];

        foreach ($invalidOptions as $invalid) {
            try {
                $pictum->placeholder(...$invalid['options']);
                self::fail('Invalid placeholder dimensions should throw.');
            } catch (InvalidArgumentException|RangeException $exception) {
                self::assertStringContainsString($invalid['message'], $exception->getMessage());
            }
        }
    }

    public function test_placeholder_density_must_be_compatible_and_within_rendered_limits(): void
    {
        $pictum = $this->pictum();

        self::assertSame(
            'https://pictum.dev/v1/placeholder.png?size=1024&density=2',
            $pictum->placeholder(size: 1024, format: 'png', density: 2)->url,
        );

        $invalidOptions = [
            ['options' => ['size' => 320, 'density' => 2], 'message' => 'not available for SVG'],
            ['options' => ['size' => 320, 'format' => 'png', 'density' => 1], 'message' => 'must be 2 or 3'],
            ['options' => ['size' => 2048, 'format' => 'webp', 'density' => 2], 'message' => 'Rendered placeholder dimensions exceed'],
        ];

        foreach ($invalidOptions as $invalid) {
            try {
                $pictum->placeholder(...$invalid['options']);
                self::fail('Invalid placeholder density should throw.');
            } catch (InvalidArgumentException|RangeException $exception) {
                self::assertStringContainsString($invalid['message'], $exception->getMessage());
            }
        }
    }

    public function test_placeholder_appearance_is_validated(): void
    {
        $pictum = $this->pictum();

        self::assertStringContainsString(
            'text='.rawurlencode(str_repeat('é', 64)),
            $pictum->placeholder(size: 320, text: str_repeat('é', 64))->url,
        );

        $invalidOptions = [
            ['options' => ['size' => 320, 'background' => 'ffffff'], 'message' => 'background must use #rrggbb or #rrggbbaa'],
            ['options' => ['size' => 320, 'color' => '12345678'], 'message' => 'color must use #rrggbb or #rrggbbaa'],
            ['options' => ['size' => 320, 'background' => "#123456\n"], 'message' => 'background must use #rrggbb or #rrggbbaa'],
            ['options' => ['size' => 320, 'color' => "#12345678\n"], 'message' => 'color must use #rrggbb or #rrggbbaa'],
            ['options' => ['size' => 320, 'background' => '#12345'], 'message' => 'background must use #rrggbb or #rrggbbaa'],
            ['options' => ['size' => 320, 'background' => '#1234567'], 'message' => 'background must use #rrggbb or #rrggbbaa'],
            ['options' => ['size' => 320, 'color' => '#123456789'], 'message' => 'color must use #rrggbb or #rrggbbaa'],
            ['options' => ['size' => 320, 'text' => str_repeat('é', 65)], 'message' => 'cannot exceed 64 characters'],
            ['options' => ['size' => 320, 'text' => "\xFF"], 'message' => 'must be valid UTF-8'],
        ];

        foreach ($invalidOptions as $invalid) {
            try {
                $pictum->placeholder(...$invalid['options']);
                self::fail('Invalid placeholder appearance should throw.');
            } catch (InvalidArgumentException|RangeException $exception) {
                self::assertStringContainsString($invalid['message'], $exception->getMessage());
            }
        }
    }

    public function test_svg_accepts_any_successful_response_and_is_not_cached(): void
    {
        $httpClient = new MockHttpClient(
            new Response(201, [], '<svg>first</svg>'),
            new Response(204, [], ''),
        );
        $asset = (new Pictum($httpClient, new HttpFactory))->icon('lucide:sparkles');

        self::assertSame('<svg>first</svg>', $asset->svg());
        self::assertSame('', $asset->svg());
        self::assertCount(2, $httpClient->requests);
    }

    public function test_svg_reports_non_successful_responses(): void
    {
        $asset = (new Pictum(
            new MockHttpClient(new Response(503, [], null, '1.1', 'Service Unavailable')),
            new HttpFactory,
        ))->icon('lucide:sparkles');

        try {
            $asset->svg();
            self::fail('A failed SVG response should throw.');
        } catch (SvgRequestFailed $exception) {
            self::assertSame(503, $exception->statusCode);
            self::assertSame('Service Unavailable', $exception->reasonPhrase);
            self::assertSame(
                'Pictum SVG request failed with status 503 Service Unavailable.',
                $exception->getMessage(),
            );
        }
    }

    public function test_svg_propagates_transport_errors(): void
    {
        $transportError = new RuntimeException('Connection failed.');
        $asset = (new Pictum(
            new MockHttpClient($transportError),
            new HttpFactory,
        ))->icon('lucide:sparkles');

        $this->expectExceptionObject($transportError);

        $asset->svg();
    }

    private function pictum(string $baseUrl = Pictum::DEFAULT_BASE_URL): Pictum
    {
        return new Pictum(new MockHttpClient, new HttpFactory, $baseUrl);
    }
}
