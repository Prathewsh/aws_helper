<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class RateLimitFilter implements FilterInterface
{
    /**
     * Maximum requests allowed per window.
     */
    protected int $maxRequests = 30;

    /**
     * Window duration in seconds (1 minute).
     */
    protected int $decaySeconds = 60;

    /**
     * Checks rate limit using cache (30 requests/minute per IP).
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $cache = service('cache');
        $ip = $request->getIPAddress();
        $cacheKey = 'rate_limit_' . md5($ip);

        $now = time();
        $rateData = $cache->get($cacheKey);

        if (! is_array($rateData) || ! isset($rateData['count'], $rateData['reset_at']) || $now >= $rateData['reset_at']) {
            $rateData = [
                'count'    => 1,
                'reset_at' => $now + $this->decaySeconds,
            ];
            $cache->save($cacheKey, $rateData, $this->decaySeconds);

            return null;
        }

        if ($rateData['count'] >= $this->maxRequests) {
            $retryAfter = max(1, $rateData['reset_at'] - $now);

            return service('response')
                ->setStatusCode(429)
                ->setContentType('application/json')
                ->setHeader('Retry-After', (string) $retryAfter)
                ->setJSON([
                    'success' => false,
                    'error'   => 'Too Many Requests',
                    'message' => "Rate limit of {$this->maxRequests} requests per minute exceeded.",
                ]);
        }

        $rateData['count']++;
        $remainingTtl = max(1, $rateData['reset_at'] - $now);
        $cache->save($cacheKey, $rateData, $remainingTtl);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No-op
    }
}

