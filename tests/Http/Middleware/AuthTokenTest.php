<?php

namespace Osiset\ShopifyApp\Test\Http\Middleware;

use Illuminate\Support\Facades\Request;
use function Osiset\ShopifyApp\base64url_encode;
use Osiset\ShopifyApp\Exceptions\HttpException;
use Osiset\ShopifyApp\Http\Middleware\AuthToken as AuthTokenMiddleware;
use Osiset\ShopifyApp\Test\TestCase;

class AuthTokenTest extends TestCase
{
    public function testDenysForMissingShopJwt(): void
    {
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            null
        );
        Request::swap($newRequest);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Missing authentication token');
        $this->expectExceptionCode(401);

        // Run the middleware
        $response = ($this->app->make(AuthTokenMiddleware::class))->handle(request(), function ($r) {
            // ...
        });
    }

    public function testDenysForBearerNoJwt(): void
    {
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_AUTHORIZATION' => 'Bearer',
            ])
        );
        Request::swap($newRequest);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Missing authentication token');
        $this->expectExceptionCode(401);

        // Run the middleware
        $response = ($this->app->make(AuthTokenMiddleware::class))->handle(request(), function () {
            // ...
        });
    }

    public function testDenysForInvalidJwt(): void
    {
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_AUTHORIZATION' => 'Bearer 1234',
            ])
        );
        Request::swap($newRequest);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Malformed token');
        $this->expectExceptionCode(400);

        // Run the middleware
        $response = ($this->app->make(AuthTokenMiddleware::class))->handle(request(), function () {
            // ...
        });
    }

    public function testDenysForValidRegexBadContent(): void
    {
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_AUTHORIZATION' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.AAAA.AAAA',
            ])
        );
        Request::swap($newRequest);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Unable to verify signature');
        $this->expectExceptionCode(400);

        // Run the middleware
        $response = ($this->app->make(AuthTokenMiddleware::class))->handle(request(), function () {
            // ...
        });
    }

    public function testDenysForValidRegexMissingContent(): void
    {
        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_AUTHORIZATION' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..',
            ])
        );
        Request::swap($newRequest);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Malformed token');
        $this->expectExceptionCode(400);

        // Run the middleware
        $response = ($this->app->make(AuthTokenMiddleware::class))->handle(request(), function () {
            // ...
        });
    }

    public function testDenysForValidRegexValidSignatureBadBody(): void
    {
        $invalidBody = base64url_encode(json_encode([
            'dest' => '<shop-name.myshopify.com>',
            'aud' => '<api key>',
            'sub' => '<user ID>',
            'exp' => '<time in seconds>',
            'nbf' => '<time in seconds>',
            'iat' => '<time in seconds>',
            'jti' => '<random UUID>',
            'sid' => '<session ID>',
        ]));

        $invalidPayload = sprintf('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.%s', $invalidBody);

        $secret = env('SHOPIFY_API_SECRET');

        $hmac = base64url_encode(hash_hmac('sha256', $invalidPayload, $secret, true));

        $validTokenInvalidBody = sprintf('%s.%s', $invalidPayload, $hmac);

        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_AUTHORIZATION' => sprintf('Bearer %s', $validTokenInvalidBody),
            ])
        );
        Request::swap($newRequest);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Malformed token');
        $this->expectExceptionCode(400);

        // Run the middleware
        $response = ($this->app->make(AuthTokenMiddleware::class))->handle(request(), function () {
            // ...
        });
    }

    public function testDenysForExpiredToken(): void
    {
        $now = time();

        $expiredBody = base64url_encode(json_encode([
            'iss' => 'https://shop-name.myshopify.com/admin',
            'dest' => 'https://shop-name.myshopify.com',
            'aud' => env('SHOPIFY_API_KEY'),
            'sub' => '123',
            'exp' => $now - 60,
            'nbf' => $now - 120,
            'iat' => $now - 120,
            'jti' => '00000000-0000-0000-0000-000000000000',
            'sid' => '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        ]));

        $payload = sprintf('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.%s', $expiredBody);

        $secret = env('SHOPIFY_API_SECRET');

        $hmac = base64url_encode(hash_hmac('sha256', $payload, $secret, true));

        $expiredTokenBody = sprintf('%s.%s', $payload, $hmac);

        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_AUTHORIZATION' => sprintf('Bearer %s', $expiredTokenBody),
            ])
        );
        Request::swap($newRequest);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Expired token');
        $this->expectExceptionCode(403);

        // Run the middleware
        $response = ($this->app->make(AuthTokenMiddleware::class))->handle(request(), function () {
            // ...
        });
    }

    public function testDenysForFutureToken(): void
    {
        $now = time();

        $expiredBody = base64url_encode(json_encode([
            'iss' => 'https://shop-name.myshopify.com/admin',
            'dest' => 'https://shop-name.myshopify.com',
            'aud' => env('SHOPIFY_API_KEY'),
            'sub' => '123',
            'exp' => $now + 60,
            'nbf' => $now + 120,
            'iat' => $now + 120,
            'jti' => '00000000-0000-0000-0000-000000000000',
            'sid' => '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        ]));

        $payload = sprintf('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.%s', $expiredBody);

        $secret = env('SHOPIFY_API_SECRET');

        $hmac = base64url_encode(hash_hmac('sha256', $payload, $secret, true));

        $expiredTokenBody = sprintf('%s.%s', $payload, $hmac);

        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_AUTHORIZATION' => sprintf('Bearer %s', $expiredTokenBody),
            ])
        );
        Request::swap($newRequest);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Expired token');
        $this->expectExceptionCode(403);

        // Run the middleware
        $response = ($this->app->make(AuthTokenMiddleware::class))->handle(request(), function () {
            // ...
        });
    }

    public function testDenysForInvalidUrl(): void
    {
        $now = time();

        $expiredBody = base64url_encode(json_encode([
            'iss' => 'https://shop-name.myshopify.com/admin',
            'dest' => 'https://another-name.myshopify.com',
            'aud' => env('SHOPIFY_API_KEY'),
            'sub' => '123',
            'exp' => $now + 60,
            'nbf' => $now,
            'iat' => $now,
            'jti' => '00000000-0000-0000-0000-000000000000',
            'sid' => '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        ]));

        $payload = sprintf('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.%s', $expiredBody);

        $secret = env('SHOPIFY_API_SECRET');

        $hmac = base64url_encode(hash_hmac('sha256', $payload, $secret, true));

        $expiredTokenBody = sprintf('%s.%s', $payload, $hmac);

        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_AUTHORIZATION' => sprintf('Bearer %s', $expiredTokenBody),
            ])
        );
        Request::swap($newRequest);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid token');
        $this->expectExceptionCode(400);

        // Run the middleware
        $response = ($this->app->make(AuthTokenMiddleware::class))->handle(request(), function () {
            // ...
        });
    }

    public function testDenysForInvalidApiKey(): void
    {
        $now = time();

        $expiredBody = base64url_encode(json_encode([
            'iss' => 'https://shop-name.myshopify.com/admin',
            'dest' => 'https://shop-name.myshopify.com',
            'aud' => 'invalid',
            'sub' => '123',
            'exp' => $now + 60,
            'nbf' => $now,
            'iat' => $now,
            'jti' => '00000000-0000-0000-0000-000000000000',
            'sid' => '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        ]));

        $payload = sprintf('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.%s', $expiredBody);

        $secret = env('SHOPIFY_API_SECRET');

        $hmac = base64url_encode(hash_hmac('sha256', $payload, $secret, true));

        $expiredTokenBody = sprintf('%s.%s', $payload, $hmac);

        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_AUTHORIZATION' => sprintf('Bearer %s', $expiredTokenBody),
            ])
        );
        Request::swap($newRequest);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid token');
        $this->expectExceptionCode(400);

        // Run the middleware
        $response = ($this->app->make(AuthTokenMiddleware::class))->handle(request(), function () {
            // ...
        });
    }

    public function testRuns(): void
    {
        $now = time();

        $body = base64url_encode(json_encode([
            'iss' => 'https://shop-name.myshopify.com/admin',
            'dest' => 'https://shop-name.myshopify.com',
            'aud' => env('SHOPIFY_API_KEY'),
            'sub' => '123',
            'exp' => $now + 60,
            'nbf' => $now,
            'iat' => $now,
            'jti' => '00000000-0000-0000-0000-000000000000',
            'sid' => '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        ]));

        $payload = sprintf('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.%s', $body);

        $secret = env('SHOPIFY_API_SECRET');

        $hmac = base64url_encode(hash_hmac('sha256', $payload, $secret, true));

        $token = sprintf('%s.%s', $payload, $hmac);

        $currentRequest = Request::instance();
        $newRequest = $currentRequest->duplicate(
            // Query Params
            [],
            // Request Params
            null,
            // Attributes
            null,
            // Cookies
            null,
            // Files
            null,
            // Server vars
            // This valid referer should be ignored as there is a get variable
            array_merge(Request::server(), [
                'HTTP_AUTHORIZATION' => sprintf('Bearer %s', $token),
            ])
        );
        Request::swap($newRequest);

        // Run the middleware
        $called = false;
        $response = ($this->app->make(AuthTokenMiddleware::class))->handle(request(), function () use (&$called) {
            $called = true;
        });

        // Assert we get a proper response
        $this->assertTrue($called);
    }
}
