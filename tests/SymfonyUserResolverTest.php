<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Xakki\SymfonyFileUploader\Security\SymfonyUserResolver;

final class SymfonyUserResolverTest extends TestCase
{
    public function test_guest_when_security_services_are_absent(): void
    {
        $resolver = new SymfonyUserResolver;

        self::assertNull($resolver->id());
        self::assertFalse($resolver->hasAnyRole([]));
        self::assertFalse($resolver->hasAnyRole(['ROLE_ADMIN']));
    }

    public function test_id_returns_the_user_identifier(): void
    {
        $user = $this->createStub(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('alice');

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $storage = $this->createStub(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);

        self::assertSame('alice', (new SymfonyUserResolver($storage))->id());
    }

    public function test_has_any_role_delegates_to_the_authorization_checker(): void
    {
        $storage = $this->createStub(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($this->createStub(TokenInterface::class));

        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(
            static fn (string $role): bool => $role === 'ROLE_ADMIN',
        );

        $resolver = new SymfonyUserResolver($storage, $checker);

        self::assertTrue($resolver->hasAnyRole(['ROLE_ADMIN']));
        self::assertFalse($resolver->hasAnyRole(['ROLE_USER']));
    }
}
