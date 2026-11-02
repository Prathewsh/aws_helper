<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class ApiTokenFilter implements FilterInterface
{
    /**
     * Enforce HTTPS in production and validate Bearer token against API_TOKEN in .env.
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // Enforce HTTPS in production
        if (ENVIRONMENT === 'production' && ! $request->isSecure()) {
            return service('response')
                ->setStatusCode(403)
                ->setContentType('application/json')
                ->setJSON([
                    'success' => false,
                    'error'   => 'HTTPS required in production.',
                ]);
        }

        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader === '') {
            return service('response')
                ->setStatusCode(401)
                ->setContentType('application/json')
                ->setJSON([
                    'success' => false,
                    'error'   => 'Unauthorized: Missing Authorization header.',
                ]);
        }

        if (! preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $matches)) {
            return service('response')
                ->setStatusCode(401)
                ->setContentType('application/json')
                ->setJSON([
                    'success' => false,
                    'error'   => 'Unauthorized: Invalid Authorization header format. Expected Bearer <token>.',
                ]);
        }

        $token = $matches[1];
        $expectedToken = (string) env('API_TOKEN');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $token)) {
            return service('response')
                ->setStatusCode(401)
                ->setContentType('application/json')
                ->setJSON([
                    'success' => false,
                    'error'   => 'Unauthorized: Invalid API token.',
                ]);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No-op
    }
}

