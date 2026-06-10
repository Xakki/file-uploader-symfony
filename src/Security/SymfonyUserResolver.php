<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Security;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;
use Xakki\FileUploader\Contracts\UserResolver;

/**
 * Core UserResolver over Symfony Security. Both dependencies are optional: when
 * SecurityBundle is not installed they are null and every request is treated as a guest.
 */
final class SymfonyUserResolver implements UserResolver
{
    public function __construct(
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ?AuthorizationCheckerInterface $authChecker = null,
    ) {}

    public function id(): ?string
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }

    public function hasAnyRole(array $roles): bool
    {
        // Bail before isGranted() when there is no checker or no authenticated token:
        // calling isGranted() prior to authentication can throw depending on config.
        if ($roles === [] || $this->authChecker === null || $this->tokenStorage?->getToken() === null) {
            return false;
        }

        foreach ($roles as $role) {
            try {
                if ($this->authChecker->isGranted($role)) {
                    return true;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }
}
