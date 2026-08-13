<?php

declare(strict_types=1);

namespace Tests\Unit\AccessControl;

use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\AccessControl\Domain\Role;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\ClientChannel;
use Modules\Shared\Exceptions\AuthorizationDeniedException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The single authorization decision point must fail closed in every direction
 * (CLAUDE.md Article 3.5, ADR 0002).
 */
final class AuthorizationServiceTest extends TestCase
{
    private AuthorizationService $authorization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorization = new AuthorizationService;
    }

    #[Test]
    public function a_guest_is_denied_everything(): void
    {
        $guest = ActorContext::guest();

        foreach (Permission::cases() as $permission) {
            $this->assertFalse($this->authorization->allows($guest, $permission));
        }
    }

    #[Test]
    public function an_authenticated_actor_without_roles_is_denied_everything(): void
    {
        // The default for every citizen account.
        $actor = ActorContext::authenticated('subject-1');

        foreach (Permission::cases() as $permission) {
            $this->assertFalse($this->authorization->allows($actor, $permission));
        }
    }

    #[Test]
    public function it_grants_exactly_the_permissions_a_role_carries(): void
    {
        $staff = $this->actorWithRoles(Role::LguStaff);

        $this->assertTrue($this->authorization->allows($staff, Permission::ServicesViewUnpublished));
        $this->assertFalse(
            $this->authorization->allows($staff, Permission::ServicesManage),
            'Front-line staff may see drafts but must not be able to manage the catalog.'
        );
    }

    #[Test]
    public function an_lgu_admin_holds_the_catalog_permissions(): void
    {
        $admin = $this->actorWithRoles(Role::LguAdmin);

        $this->assertTrue($this->authorization->allows($admin, Permission::ServicesViewUnpublished));
        $this->assertTrue($this->authorization->allows($admin, Permission::ServicesManage));
    }

    #[Test]
    public function a_permission_outside_the_catalog_is_denied(): void
    {
        // A typo at a call site must fail closed, never open.
        $admin = $this->actorWithRoles(Role::LguAdmin);

        $this->assertFalse($this->authorization->allows($admin, 'services.view_unpublishd'));
        $this->assertFalse($this->authorization->allows($admin, 'anything.at.all'));
    }

    #[Test]
    public function a_permission_smuggled_into_the_actor_is_still_denied_if_unknown(): void
    {
        $actor = ActorContext::authenticated('subject-1', ['resident'], ['system.root']);

        $this->assertFalse($this->authorization->allows($actor, 'system.root'));
    }

    #[Test]
    public function authorize_throws_for_a_denied_actor(): void
    {
        $this->expectException(AuthorizationDeniedException::class);

        $this->authorization->authorize(ActorContext::guest(), Permission::ServicesManage);
    }

    #[Test]
    public function authorize_is_silent_for_a_permitted_actor(): void
    {
        $this->expectNotToPerformAssertions();

        $this->authorization->authorize($this->actorWithRoles(Role::LguAdmin), Permission::ServicesManage);
    }

    #[Test]
    public function the_client_channel_never_changes_a_decision(): void
    {
        foreach (ClientChannel::cases() as $channel) {
            $actor = ActorContext::authenticated('subject-1', [], [], $channel);

            $this->assertFalse(
                $this->authorization->allows($actor, Permission::ServicesViewUnpublished),
                "Channel `{$channel->value}` must not affect an authorization decision."
            );
        }
    }

    private function actorWithRoles(Role ...$roles): ActorContext
    {
        $names = array_map(static fn (Role $role): string => $role->value, $roles);

        return ActorContext::authenticated('subject-1', $names, Role::permissionsFor($names));
    }
}
