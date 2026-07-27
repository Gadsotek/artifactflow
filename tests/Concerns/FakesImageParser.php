<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Application\PageCatalog\ImageArtifactLimits;
use App\Application\PageCatalog\ImageParserConfiguration;
use ArtifactFlow\ImageParser\ParserConfiguration;
use ArtifactFlow\ImageParser\ParserOutputTooLarge;
use ArtifactFlow\ImageParser\ParserRejection;
use ArtifactFlow\ImageParser\ParserRequest;
use ArtifactFlow\ImageParser\RasterNormalizer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

trait FakesImageParser
{
    protected function fakeImageParser(): void
    {
        if (!class_exists(ParserConfiguration::class)) {
            require_once base_path('image-parser/src/ImageParser.php');
        }

        config([
            'image_parser.url' => 'http://image-parser.test',
            'image_parser.shared_secret' => 'test-image-parser-shared-secret-0001',
        ]);

        Http::fake(function (Request $request): \GuzzleHttp\Promise\PromiseInterface {
            $limits = app(ImageArtifactLimits::class);
            $secret = app(ImageParserConfiguration::class)->sharedSecret();
            $nonce = $request->header('X-ArtifactFlow-Parser-Nonce')[0] ?? '';
            $mediaType = $request->header('Content-Type')[0] ?? '';
            $maxInputBytes = $request->header('X-ArtifactFlow-Parser-Max-Input-Bytes')[0] ?? '';
            $maxOutputBytes = $request->header('X-ArtifactFlow-Parser-Max-Output-Bytes')[0] ?? '';
            $maxPixels = $request->header('X-ArtifactFlow-Parser-Max-Pixels')[0] ?? '';
            $maxDimension = $request->header('X-ArtifactFlow-Parser-Max-Dimension')[0] ?? '';

            if (
                !is_string($nonce)
                || !is_string($mediaType)
                || !is_string($maxInputBytes)
                || !is_string($maxOutputBytes)
                || !is_string($maxPixels)
                || !is_string($maxDimension)
                || !ctype_digit($maxInputBytes)
                || !ctype_digit($maxOutputBytes)
                || !ctype_digit($maxPixels)
                || !ctype_digit($maxDimension)
            ) {
                return Http::response(['error' => 'image_rejected'], 422);
            }

            $configuration = new ParserConfiguration(
                sharedSecret: $secret,
                maxInputBytes: $limits->maxUploadBytes(),
                maxOutputBytes: 64 * 1024 * 1024,
                maxPixels: $limits->maxUploadPixels(),
                maxDimension: $limits->maxUploadDimension(),
                maxClockSkewSeconds: 30,
            );
            $parserRequest = new ParserRequest(
                nonce: $nonce,
                mediaType: $mediaType,
                bytes: $request->body(),
                maxOutputBytes: (int) $maxOutputBytes,
                maxInputBytes: (int) $maxInputBytes,
                maxPixels: (int) $maxPixels,
                maxDimension: (int) $maxDimension,
            );

            try {
                [$image, $normalized] = (new RasterNormalizer($configuration))->normalize($parserRequest);
            } catch (ParserOutputTooLarge) {
                return Http::response(['error' => 'normalized_image_too_large'], 422);
            } catch (ParserRejection) {
                return Http::response(['error' => 'image_rejected'], 422);
            }

            $signature = hash_hmac('sha256', implode("\n", [
                'artifactflow-image-parser-response-v1',
                $nonce,
                $image->mediaType,
                (string) $image->width,
                (string) $image->height,
                hash('sha256', $normalized),
            ]), $secret);

            return Http::response($normalized, 200, [
                'Content-Type' => $image->mediaType,
                'X-ArtifactFlow-Parser-Media-Type' => $image->mediaType,
                'X-ArtifactFlow-Parser-Width' => (string) $image->width,
                'X-ArtifactFlow-Parser-Height' => (string) $image->height,
                'X-ArtifactFlow-Parser-Signature' => $signature,
            ]);
        });
    }
}
