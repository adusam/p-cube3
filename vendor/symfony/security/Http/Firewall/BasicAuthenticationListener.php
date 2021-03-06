<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Firewall;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\AuthenticationManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * BasicAuthenticationListener implements Basic HTTP authentication.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class BasicAuthenticationListener implements ListenerInterface
{
    private $tokenStorage;
    private $authenticationManager;
    private $providerKey;
    private $authenticationEntryPoint;
    private $logger;
    private $ignoreFailure;

    public function __construct(TokenStorageInterface $tokenStorage, AuthenticationManagerInterface $authenticationManager, $providerKey, AuthenticationEntryPointInterface $authenticationEntryPoint, LoggerInterface $logger = null)
    {
        if (empty($providerKey)) {
            throw new \InvalidArgumentException('$providerKey must not be empty.');
        }

        $this->tokenStorage = $tokenStorage;
        $this->authenticationManager = $authenticationManager;
        $this->providerKey = $providerKey;
        $this->authenticationEntryPoint = $authenticationEntryPoint;
        $this->logger = $logger;
        $this->ignoreFailure = false;
    }

    /**
     * Handles basic authentication.
     */
    public function handle(GetResponseEvent $event)
    {
        $request = $event->getRequest();

        if (null === $username = $request->headers->get('PHP_AUTH_USER')) {
            return;
        }

        if (null !== $token = $this->tokenStorage->getToken()) {
            if ($token instanceof UsernamePasswordToken && $token->isAuthenticated() && $token->getUsername() === $username) {
                return;
            }
        }

        if (null !== $this->logger) {
            $this->logger->info('Basic authentication Authorization header found for user.', array('username' => $username));
        }

        try {
            $token = $this->authenticationManager->authenticate(new UsernamePasswordToken($username, $request->headers->get('PHP_AUTH_PW'), $this->providerKey));

            $this->migrateSession($request);

            $this->tokenStorage->setToken($token);
        } catch (AuthenticationException $e) {
            $token = $this->tokenStorage->getToken();
            if ($token instanceof UsernamePasswordToken && $this->providerKey === $token->getProviderKey()) {
                $this->tokenStorage->setToken(null);
            }

            if (null !== $this->logger) {
                $this->logger->info('Basic authentication failed for user.', array('username' => $username, 'exception' => $e));
            }

            if ($this->ignoreFailure) {
                return;
            }

            $event->setResponse($this->authenticationEntryPoint->start($request, $e));
        }
    }

    private function migrateSession(Request $request)
    {
        if (!$request->hasSession() || !$request->hasPreviousSession()) {
            return;
        }

        // Destroying the old session is broken in php 5.4.0 - 5.4.10
        // See https://bugs.php.net/63379
        $destroy = \PHP_VERSION_ID < 50400 || \PHP_VERSION_ID >= 50411;
        $request->getSession()->migrate($destroy);
    }
}
