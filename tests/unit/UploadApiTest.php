<?php

namespace Tests\Unit;

use App\Filters\ApiTokenFilter;
use App\Filters\RateLimitFilter;
use App\Controllers\Api\UploadController;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use ReflectionClass;

/**
 * @internal
 */
final class UploadApiTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
    }

    public function testApiTokenFilterMissingHeader(): void
    {
        $filter = new ApiTokenFilter();
        $request = new IncomingRequest(new App(), new URI('http://example.com/api/upload'), null, new UserAgent());

        $response = $filter->before($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('Missing Authorization header', $body['error']);
    }

    public function testApiTokenFilterInvalidFormat(): void
    {
        $filter = new ApiTokenFilter();
        $request = (new IncomingRequest(new App(), new URI('http://example.com/api/upload'), null, new UserAgent()))
            ->setHeader('Authorization', 'Basic 12345');

        $response = $filter->before($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('Expected Bearer <token>', $body['error']);
    }

    public function testApiTokenFilterInvalidToken(): void
    {
        putenv('API_TOKEN=valid-secret-token');
        $_ENV['API_TOKEN'] = 'valid-secret-token';

        $filter = new ApiTokenFilter();
        $request = (new IncomingRequest(new App(), new URI('http://example.com/api/upload'), null, new UserAgent()))
            ->setHeader('Authorization', 'Bearer wrong-token');

        $response = $filter->before($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Unauthorized: Invalid API token.', $body['error']);
    }

    public function testApiTokenFilterValidToken(): void
    {
        putenv('API_TOKEN=valid-secret-token');
        $_ENV['API_TOKEN'] = 'valid-secret-token';

        $filter = new ApiTokenFilter();
        $request = (new IncomingRequest(new App(), new URI('http://example.com/api/upload'), null, new UserAgent()))
            ->setHeader('Authorization', 'Bearer valid-secret-token');

        $response = $filter->before($request);
        $this->assertNull($response);
    }

    public function testRateLimitFilterExceeded(): void
    {
        Services::cache()->clean();

        $filter = new RateLimitFilter();
        $request = new IncomingRequest(new App(), new URI('http://example.com/api/upload'), null, new UserAgent());

        for ($i = 0; $i < 30; $i++) {
            $result = $filter->before($request);
            $this->assertNull($result);
        }

        // 31st request should be throttled (HTTP 429)
        $response = $filter->before($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(429, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Too Many Requests', $body['error']);
    }

    public function testPathSanitization(): void
    {
        $controller = new UploadController();
        $ref = new ReflectionClass($controller);
        $method = $ref->getMethod('sanitizePath');
        $method->setAccessible(true);

        $this->assertSame('clips', $method->invoke($controller, 'clips/'));
        $this->assertSame('clips/summer', $method->invoke($controller, '/clips/summer/'));
        $this->assertSame('etc/passwd', $method->invoke($controller, '../../etc/passwd'));
        $this->assertSame('', $method->invoke($controller, '../..'));
        $this->assertSame('clips/bad', $method->invoke($controller, 'clips/../bad'));
        $this->assertSame('clips/sub', $method->invoke($controller, 'clips//sub///'));
        $this->assertSame('', $method->invoke($controller, null));
    }

    public function testFilenameSanitization(): void
    {
        $controller = new UploadController();
        $ref = new ReflectionClass($controller);
        $method = $ref->getMethod('sanitizeFilename');
        $method->setAccessible(true);

        $this->assertSame('video.mp4', $method->invoke($controller, 'video.mp4'));
        $this->assertSame('my_vacation_video.mp4', $method->invoke($controller, 'my vacation video.mp4'));
        $this->assertSame('secret.png', $method->invoke($controller, '../../secret.png'));
        $this->assertSame('file_tar.gz', $method->invoke($controller, 'file.tar.gz'));
        $this->assertSame('file.jpg', $method->invoke($controller, '!@#$.jpg'));
    }

    public function testUuidGeneration(): void
    {
        $controller = new UploadController();
        $ref = new ReflectionClass($controller);
        $method = $ref->getMethod('generateUuid');
        $method->setAccessible(true);

        $uuid1 = $method->invoke($controller);
        $uuid2 = $method->invoke($controller);

        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $this->assertMatchesRegularExpression($pattern, $uuid1);
        $this->assertMatchesRegularExpression($pattern, $uuid2);
        $this->assertNotSame($uuid1, $uuid2);
    }

    public function testUploadValidationFailsOnMissingFields(): void
    {
        $request = new IncomingRequest(new App(), new URI('http://example.com/api/upload'), null, new UserAgent());
        $response = new Response(new App());

        $controller = new UploadController();
        $controller->initController($request, $response, Services::logger());

        $res = $controller->upload();
        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame(422, $res->getStatusCode());

        $body = json_decode($res->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Validation failed', $body['error']);
        $this->assertArrayHasKey('bucket', $body['messages']);
        $this->assertArrayHasKey('region', $body['messages']);
        $this->assertArrayHasKey('access_key', $body['messages']);
        $this->assertArrayHasKey('secret_key', $body['messages']);
        $this->assertArrayHasKey('file', $body['messages']);
    }

    public function testJsonExceptionHandlerFormat(): void
    {
        $handler = new \App\Libraries\JsonExceptionHandler(config('Exceptions'));
        $request = new IncomingRequest(new App(), new URI('http://example.com/api/upload'), null, new UserAgent());
        $response = new Response(new App());

        // In testing environment (ENVIRONMENT === 'testing'), exit() is skipped
        $exception = new \RuntimeException('Database connection lost');

        ob_start();
        $handler->handle($exception, $request, $response, 500, 1);
        $output = ob_get_clean();

        // The response was sent or output buffered
        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame(500, $body['status']);
        $this->assertSame('Database connection lost', $body['error']);
        $this->assertSame('RuntimeException', $body['exception']);
    }

    public function testBasePathShowsPermissionDeniedPage(): void
    {
        $controller = new \App\Controllers\Home();
        $output = $controller->index();

        $this->assertStringContainsString('Permission denied', $output);
        $this->assertStringContainsString("You don't have access to this resource.", $output);
        $this->assertStringContainsString('&copy; ' . date('Y') . ' STPL', $output);
    }
}
