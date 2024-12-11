<?php

/**
 * Cloudflare Turnstile service.
 *
 * PHP version 8
 *
 * Copyright (C) Villanova University 2024.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  RateLimiter
 * @author   Maccabee Levine <msl321@lehigh.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */

namespace VuFind\RateLimiter\Turnstile;

use Laminas\Cache\Storage\StorageInterface;
use Laminas\Log\LoggerAwareInterface;
use Laminas\Mvc\MvcEvent;
use VuFind\Log\LoggerAwareTrait;
use VuFindHttp\HttpServiceAwareInterface;
use VuFindHttp\HttpServiceAwareTrait;

/**
 * Rate limiter manager.
 *
 * @category VuFind
 * @package  RateLimiter
 * @author   Maccabee Levine <msl321@lehigh.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
class Turnstile implements HttpServiceAwareInterface, LoggerAwareInterface
{
    use HttpServiceAwareTrait;
    use LoggerAwareTrait;

    /**
     * Constructor
     *
     * @param array            $config         Rate Limiter config
     * @param StorageInterface $turnstileCache Cache for Turnstile results
     */
    public function __construct(
        protected array $config,
        protected StorageInterface $turnstileCache,
    ) {
    }

    /**
     * Determines if a Turnstile challenge is allowed based on the current event.
     *
     * @param MvcEvent $event The MVC event
     *
     * @return bool Whether or not the challenge is allowed
     */
    public function isChallengeAllowed($event)
    {
        $routeMatch = $event->getRouteMatch();
        $controller = $routeMatch?->getParam('controller') ?? '??';
        $skipOnControllerPattern = $this->config['Turnstile']['skipOnControllerPattern'] ?? '/AJAX|Cover|Api/';
        $skip = preg_match($skipOnControllerPattern, $controller);
        return !$skip;
    }

    /**
     * Validate a token against the Turnstile API
     *
     * @param string $token The token generated by the Turnstile widget
     *
     * @return bool Whether validation was successful
     */
    public function validateToken($token)
    {
        // Call the Turnstile verify API to validate the token
        $secretKey = $this->config['Turnstile']['secretKey'];
        $url = $this->config['Turnstile']['verifyUrl'] ??
            'https://challenges.cloudflare.com/turnstile/v0/siteverify';
        $body = [
            'secret' => $secretKey,
            'response' => $token,
        ];
        $response = $this->httpService->post(
            $url,
            json_encode($body),
            'application/json'
        );

        if ($response->isOk()) {
            $responseData = json_decode($response->getBody(), true);
            $success = $responseData['success'];
        } else {
            // Unexpected error. Treat as a positive result, since it's not the user's fault.
            $this->logWarning('Verification process failed, allowing traffic: '
                . $response->getStatusCode() . ' ' . $response->getBody());
            $success = true;
        }

        return $success;
    }

    /**
     * Validate a token and save the result
     *
     * @param string $token    The token generated by the Turnstile widget
     * @param string $policyId RateLimiterManager policy ID
     * @param string $clientIp Client IP address
     *
     * @return bool Whether validation was successful
     */
    public function validateAndCacheResult($token, $policyId, $clientIp)
    {
        $success = $this->validateToken($token);
        $this->setResult($policyId, $clientIp, $success);
        return $success;
    }

    /**
     * Check for a prior, cached result from Turnstile under this client IP and policy.
     *
     * @param string $policyId The policy ID
     * @param string $clientIp The client IP
     *
     * @return ?bool Null if there is no prior result, or if Turnstile is disabled;
     *               otherwise a boolean representing the Turnstile result.
     */
    public function checkPriorResult($policyId, $clientIp)
    {
        if (!($this->config['Policies'][$policyId]['turnstileRateLimiterSettings'] ?? false)) {
            return null;
        }
        $cacheKey = $this->getCacheKey($policyId, $clientIp);
        return $this->turnstileCache->getItem($cacheKey);
    }

    /**
     * Store a Turnstile result for this client IP and policy.
     *
     * @param string $policyId The policy ID
     * @param string $clientIp The client IP
     * @param bool   $success  The result to store
     *
     * @return void
     */
    protected function setResult($policyId, $clientIp, $success)
    {
        $cacheKey = $this->getCacheKey($policyId, $clientIp);
        $this->turnstileCache->setItem($cacheKey, $success);
    }

    /**
     * Generate a key for the Turnstile cache.
     *
     * @param string $policyId The policy ID
     * @param string $clientIp The client IP
     *
     * @return string The cache key
     */
    protected function getCacheKey($policyId, $clientIp)
    {
        $key = $policyId . '--' . $clientIp;
        $key = str_replace('.', '-', $key);
        return $key;
    }
}