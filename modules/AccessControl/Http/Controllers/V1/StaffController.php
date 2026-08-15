<?php

declare(strict_types=1);

namespace Modules\AccessControl\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Application\ScopeResolver;
use Modules\AccessControl\Application\StaffProvisioningService;
use Modules\AccessControl\Contracts\Permission;
use Modules\AccessControl\Domain\Role;
use Modules\AccessControl\Domain\RoleAssignmentRepository;
use Modules\Identity\Application\StaffAccountProvisioner;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\DataScope;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * Staff provisioning for the admin console (ADR 0012).
 *
 * The controller does what every controller in this codebase does and no more: validate
 * shape, resolve the actor server-side, call the application service, shape the response
 * (CLAUDE.md Article 3.2). Every escalation rule lives in StaffProvisioningService, so
 * adding a second caller — a CLI command, an import — cannot bypass it.
 *
 * Note that no route below takes a role, permission or scope from anywhere except an
 * explicitly validated allow-list. A body key the validator does not name never reaches a
 * write: mass assignment is how "grant a clerk read access" becomes "grant a clerk
 * everything" (Article 3.4).
 */
final class StaffController
{
    public function __construct(
        private readonly StaffProvisioningService $provisioning,
        private readonly StaffAccountProvisioner $accounts,
        private readonly AuthorizationService $authorization,
        private readonly ScopeResolver $scopes,
        private readonly RoleAssignmentRepository $assignments,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::StaffView);

        $pagination = PaginationParams::fromRequest($request);
        $directory = $this->accounts->paginate($pagination->page, $pagination->perPage);

        return ApiResponse::page(
            new Page($directory['items'], $directory['total'], $pagination),
            fn ($summary): array => $summary->toArray() + [
                'authority' => $this->authorityFor($summary->id),
            ],
        );
    }

    public function show(Request $request, ActorContext $actor, string $staff): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::StaffView);

        $summary = $this->accounts->summaryFor($staff);

        if ($summary === null) {
            throw ResourceNotFoundException::make('That staff member was not found.');
        }

        return ApiResponse::item($summary->toArray() + ['authority' => $this->authorityFor($summary->id)]);
    }

    /**
     * Creates a staff account with no authority at all.
     *
     * Roles are a separate call on purpose. One request that both creates an account and
     * grants it power is one request an attacker only has to win once, and it makes the
     * audit trail say "created" where it should say "created, then granted X by Y".
     */
    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::StaffManage);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'display_name' => ['required', 'string', 'max:128'],
        ]);

        $summary = $this->accounts->create($validated['email'], $validated['display_name']);

        return ApiResponse::item(
            $summary->toArray() + ['authority' => $this->authorityFor($summary->id)],
            201,
        );
    }

    public function assignRole(Request $request, ActorContext $actor, string $staff): JsonResponse
    {
        // Checked here as well as in the service: staffOrFail() below reveals whether an
        // id exists, and an unauthorized caller must not learn even that.
        $this->authorization->authorize($actor, Permission::StaffManage);

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:'.$this->assignableRoles()],
            'scope_type' => ['required', 'string', 'in:'.implode(',', [
                DataScope::ALL_BARANGAYS,
                DataScope::OWN_BARANGAY,
                DataScope::ASSIGNED_CASES,
            ])],
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
        ]);

        $subject = $this->staffOrFail($staff);

        $this->provisioning->assignRole(
            $actor,
            $subject,
            Role::from($validated['role']),
            $validated['scope_type'],
            $validated['barangay_id'] ?? null,
        );

        return ApiResponse::item(['id' => $subject, 'authority' => $this->authorityFor($subject)]);
    }

    public function revokeRole(Request $request, ActorContext $actor, string $staff, string $role): JsonResponse
    {
        // Checked here as well as in the service: staffOrFail() below reveals whether an
        // id exists, and an unauthorized caller must not learn even that.
        $this->authorization->authorize($actor, Permission::StaffManage);

        $subject = $this->staffOrFail($staff);
        $model = Role::tryFrom($role);

        if ($model === null) {
            throw ResourceNotFoundException::make('That role was not found.');
        }

        $this->provisioning->revokeRole($actor, $subject, $model);

        return ApiResponse::item(['id' => $subject, 'authority' => $this->authorityFor($subject)]);
    }

    public function grantBarangay(Request $request, ActorContext $actor, string $staff): JsonResponse
    {
        // Checked here as well as in the service: staffOrFail() below reveals whether an
        // id exists, and an unauthorized caller must not learn even that.
        $this->authorization->authorize($actor, Permission::StaffManage);

        $validated = $request->validate([
            'barangay_id' => ['required', 'integer', 'exists:barangays,id'],
            // Required, not optional. An unexplained standing grant is the one nobody
            // reviews, and reviewing them is the only thing that keeps scopes narrow.
            'reason' => ['required', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date', 'after:now'],
        ]);

        $subject = $this->staffOrFail($staff);

        $this->provisioning->grantBarangay(
            $actor,
            $subject,
            (int) $validated['barangay_id'],
            $validated['reason'],
            $validated['valid_until'] ?? null,
        );

        return ApiResponse::item(['id' => $subject, 'authority' => $this->authorityFor($subject)]);
    }

    public function revokeBarangay(Request $request, ActorContext $actor, string $staff, string $barangay): JsonResponse
    {
        // Checked here as well as in the service: staffOrFail() below reveals whether an
        // id exists, and an unauthorized caller must not learn even that.
        $this->authorization->authorize($actor, Permission::StaffManage);

        $subject = $this->staffOrFail($staff);

        $this->provisioning->revokeBarangayGrant($actor, $subject, (int) $barangay);

        return ApiResponse::item(['id' => $subject, 'authority' => $this->authorityFor($subject)]);
    }

    /**
     * Deactivates the account and drops its tokens.
     *
     * Roles are left in place: they are the record of what this person could do while they
     * held the account, and an inactive account carries no authority regardless
     * (ActorContextFactory). Erasing the assignments would only erase the evidence.
     */
    public function deactivate(Request $request, ActorContext $actor, string $staff): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::StaffManage);

        $subject = $this->staffOrFail($staff);

        if ($actor->isSubject($subject)) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'You cannot deactivate your own account. Ask another administrator.',
            );
        }

        $summary = $this->accounts->deactivate($subject);

        if ($summary === null) {
            throw ResourceNotFoundException::make('That staff member was not found.');
        }

        return ApiResponse::item($summary->toArray() + ['authority' => $this->authorityFor($subject)]);
    }

    /**
     * The permission catalog, so the console can render a grant screen from the server's
     * vocabulary rather than a hard-coded copy that drifts.
     *
     * It is reference data, not authority: knowing that `kyc.approve` exists grants
     * nothing, and the console still cannot act on anything the server refuses.
     */
    public function catalog(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::StaffView);

        return ApiResponse::item([
            'permissions' => array_map(
                static fn (Permission $permission): string => $permission->value,
                Permission::cases(),
            ),
            'roles' => array_map(static fn (Role $role): array => [
                'name' => $role->value,
                'permissions' => Role::permissionsFor([$role->value]),
                // What this actor may actually hand out. The console can grey out the
                // rest — and the server refuses them anyway if it does not.
                'grantable' => array_diff(Role::permissionsFor([$role->value]), $actor->permissions) === [],
            ], Role::cases()),
            'scope_types' => [DataScope::ALL_BARANGAYS, DataScope::OWN_BARANGAY, DataScope::ASSIGNED_CASES],
        ]);
    }

    /**
     * Resolves a staff identifier, or 404s.
     *
     * Callers authorize *before* reaching here, because a 404-vs-403 difference is itself
     * an oracle: without that ordering an unauthorized caller could map the staff
     * directory by probing ids.
     */
    private function staffOrFail(string $uuid): string
    {
        $summary = $this->accounts->summaryFor($uuid);

        if ($summary === null) {
            throw ResourceNotFoundException::make('That staff member was not found.');
        }

        return $summary->id;
    }

    /**
     * @return array{roles: list<string>, permissions: list<string>, scope: array{type: string, barangay_ids: list<int>}}
     */
    private function authorityFor(string $subjectId): array
    {
        return $this->provisioning->describeAuthority(
            $subjectId,
            $this->scopes,
            $this->assignments->rolesFor($subjectId),
        );
    }

    /**
     * Roles a request may name at all. Narrower than the catalog is checked again in the
     * service — this only keeps an invalid string out of `Role::from()`.
     */
    private function assignableRoles(): string
    {
        return implode(',', array_map(static fn (Role $role): string => $role->value, Role::cases()));
    }
}
