<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\ClientChannel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActorContextTest extends TestCase
{
    #[Test]
    public function a_guest_holds_no_identity_and_no_permissions(): void
    {
        $guest = ActorContext::guest();

        $this->assertTrue($guest->isGuest());
        $this->assertFalse($guest->isAuthenticated());
        $this->assertNull($guest->subjectId);
        $this->assertSame([], $guest->permissions);
        $this->assertSame([], $guest->roles);
    }

    #[Test]
    public function it_deduplicates_roles_and_permissions(): void
    {
        $actor = ActorContext::authenticated(
            'subject-1',
            ['lgu_staff', 'lgu_staff'],
            ['services.view_unpublished', 'services.view_unpublished'],
        );

        $this->assertSame(['lgu_staff'], $actor->roles);
        $this->assertSame(['services.view_unpublished'], $actor->permissions);
    }

    #[Test]
    public function permission_checks_are_exact_matches(): void
    {
        $actor = ActorContext::authenticated('subject-1', [], ['services.view_unpublished']);

        $this->assertTrue($actor->hasPermission('services.view_unpublished'));
        $this->assertFalse($actor->hasPermission('services.view'));
        $this->assertFalse($actor->hasPermission('SERVICES.VIEW_UNPUBLISHED'));
        $this->assertFalse($actor->hasPermission('services.manage'));
    }

    #[Test]
    public function ownership_comparison_rejects_null_and_mismatched_subjects(): void
    {
        $actor = ActorContext::authenticated('resident-42');

        $this->assertTrue($actor->isSubject('resident-42'));
        $this->assertFalse($actor->isSubject('resident-43'));
        $this->assertFalse($actor->isSubject(null));

        // A guest must never be treated as the owner of an unowned record.
        $this->assertFalse(ActorContext::guest()->isSubject(null));
    }

    #[Test]
    public function the_audit_projection_carries_no_personal_data(): void
    {
        $actor = ActorContext::authenticated('subject-1', ['lgu_staff'], ['services.view_unpublished'], ClientChannel::AdminConsole);

        $this->assertSame(
            ['subject_id' => 'subject-1', 'roles' => ['lgu_staff'], 'channel' => 'admin-console'],
            $actor->forAudit(),
            'Audit records must carry identifiers and authority only (CLAUDE.md Article 5.5).'
        );
    }
}
