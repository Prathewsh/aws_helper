<?php

namespace App\Libraries;

use CodeIgniter\Debug\BaseExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class JsonExceptionHandler extends BaseExceptionHandler implements ExceptionHandlerInterface
{
    /**
     * Handles exceptions and ensures a JSON response is returned.
     */
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode
    ): void {
        if ($statusCode < 400 || $statusCode > 599) {
            $statusCode = 500;
        }

        $accept = $request->getHeaderLine('accept');
        $uriPath = trim($request->getUri()->getPath(), '/');
        $isApiRoute = str_starts_with($uriPath, 'api');

        // Render permission denied view for browser visits to non-API paths
        if (! $isApiRoute && str_contains($accept, 'text/html')) {
            $html = view('permission_denied');

            $response->setStatusCode($statusCode)
                ->setContentType('text/html')
                ->setBody($html)
                ->noCache()
                ->send();

            if (ENVIRONMENT !== 'testing') {
                exit($exitCode);
            }

            return;
        }

        $isProduction = (ENVIRONMENT === 'production');
        $message = $exception->getMessage();

        if ($statusCode === 404) {
            $errorMessage = $message !== '' ? $message : 'Page Not Found';
        } elseif ($isProduction && $statusCode === 500) {
            $errorMessage = 'Internal Server Error';
        } else {
            $errorMessage = $message !== '' ? $message : 'An error occurred';
        }

        $payload = [
            'success' => false,
            'status'  => $statusCode,
            'error'   => $errorMessage,
        ];

        // Include debugging info only in non-production environments
        if (! $isProduction) {
            $payload['exception'] = get_class($exception);
            $payload['file']      = $exception->getFile();
            $payload['line']      = $exception->getLine();
        }

        $response->setStatusCode($statusCode)
            ->setContentType('application/json')
            ->setJSON($payload)
            ->noCache()
            ->send();

        if (ENVIRONMENT !== 'testing') {
            exit($exitCode);
        }
    }
}

