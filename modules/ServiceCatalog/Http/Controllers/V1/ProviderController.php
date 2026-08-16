<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ServiceCatalog\Application\ProviderDirectory;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ServiceProvider;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * The service provider directory, for staff (ADR 0021 §1).
 *
 * Staff-only, and not because the information is secret — most of it is on a signboard. It is
 * because a public directory of "offices the MSWDO refers welfare clients to" is a map of where
 * vulnerable people are sent, and publishing one invites impersonation of exactly the offices
 * families are told to trust.
 */
final class ProviderController
{
    public function __construct(
        private readonly ProviderDirectory $directory,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        // Any staff member preparing a referral needs to read the directory.
        $this->authorization->authorize($actor, Permission::ReferralView);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->directory->query();

        $search = $request->query('search');

        if (is_string($search) && $search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        foreach (['destination_type', 'status'] as $filter) {
            $value = $request->query($filter);

            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (ServiceProvider $provider): array => $this->projection($provider),
        );
    }

    public function show(Request $request, ActorContext $actor, string $provider): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralView);

        return ApiResponse::item($this->projection($this->providerOrFail($provider)));
    }

    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProviderManage);

        $validated = $request->validate($this->rules());

        return ApiResponse::created($this->projection($this->directory->create($validated, $actor)));
    }

    public function update(Request $request, ActorContext $actor, string $provider): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProviderManage);

        $model = $this->providerOrFail($provider);
        $validated = $request->validate($this->rules(partial: true));

        return ApiResponse::item($this->projection($this->directory->update($model, $validated, $actor)));
    }

    public function changeStatus(Request $request, ActorContext $actor, string $provider): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProviderManage);

        $model = $this->providerOrFail($provider);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended,retired'],
        ]);

        return ApiResponse::item($this->projection(
            $this->directory->changeStatus($model, $validated['status'], $actor),
        ));
    }

    /**
     * Somebody has re-checked this entry against reality.
     */
    public function verify(Request $request, ActorContext $actor, string $provider): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ProviderManage);

        return ApiResponse::item($this->projection(
            $this->directory->markVerified($this->providerOrFail($provider), $actor),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:160'],
            'destination_type' => [$required, 'string', 'max:48'],
            'services_offered' => [$required, 'array', 'min:1'],
            'services_offered.*' => ['string', 'max:120'],
            'channels' => [$required, 'array', 'min:1'],
            'channels.*' => ['string', 'in:letter,email,phone,in-person,system'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'barangay_id' => ['sometimes', 'nullable', 'integer'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_position' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'usual_response_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(ServiceProvider $provider): array
    {
        return [
            'id' => $provider->uuid,
            'name' => $provider->name,
            'destination_type' => $provider->destination_type,
            'status' => $provider->status,
            'services_offered' => $provider->servicesOffered(),
            'channels' => $provider->channels(),
            'address' => $provider->address,
            'contact' => [
                'person' => $provider->contact_person,
                'position' => $provider->contact_position,
                'phone' => $provider->contact_phone,
                'email' => $provider->contact_email,
            ],
            // Named as this office's observation rather than the provider's promise, so a worker
            // chasing on day 8 knows they are applying an internal convention.
            'usual_response_days' => $provider->usual_response_days === null
                ? null
                : (int) $provider->usual_response_days,
            'notes' => $provider->notes,
            'verified_at' => $provider->verified_at?->toIso8601ZuluString(),
            // Surfaced so the console can show why an entry cannot be activated, rather than
            // presenting a refusal with no explanation.
            'problems' => $provider->problems(),
            'is_accepting_referrals' => $provider->isAcceptingReferrals(),
        ];
    }

    private function providerOrFail(string $uuid): ServiceProvider
    {
        /** @var ServiceProvider|null $provider */
        $provider = ServiceProvider::query()->where('uuid', $uuid)->first();

        if ($provider === null) {
            throw ResourceNotFoundException::make('That service provider was not found.');
        }

        return $provider;
    }
}
