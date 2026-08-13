<?php

declare(strict_types=1);

use Modules\ServiceCatalog\Domain\PublicationStatus;
use Modules\ServiceCatalog\Domain\ServiceCategory;
use Modules\Shared\Application\ClientChannel;

return [

    /*
    |--------------------------------------------------------------------------
    | LGU service catalog (PROVISIONAL STORE)
    |--------------------------------------------------------------------------
    |
    | Seed content for the catalog, read through
    | Modules\ServiceCatalog\Infrastructure\ConfigServiceCatalogRepository. It becomes an
    | LGU-editable table when the write use cases are designed; the repository interface
    | does not change, so no caller is affected.
    |
    | `id` values are fixed UUIDs so that identifiers stay stable across the migration to
    | persistence — client-facing identifiers must never be renumbered.
    |
    | The one `draft` entry is load-bearing: it is what proves, in tests, that an
    | unpublished entry is invisible to citizens and visible to staff holding
    | `services.view_unpublished`.
    |
    */

    'services' => [
        [
            'id' => '018f2c8a-0a01-7000-8000-00000000c001',
            'code' => 'CEDULA',
            'name' => 'Community Tax Certificate (Cedula)',
            'description' => 'Application and issuance of the community tax certificate.',
            'category' => ServiceCategory::Dokumento->value,
            'status' => PublicationStatus::Published->value,
            'channels' => [
                ClientChannel::CitizenWeb->value,
                ClientChannel::CitizenMobile->value,
                ClientChannel::AdminConsole->value,
            ],
        ],
        [
            'id' => '018f2c8a-0a01-7000-8000-00000000c002',
            'code' => 'BUSINESS_PERMIT',
            'name' => 'Business Permit Application',
            'description' => 'New and renewal applications for a municipal business permit.',
            'category' => ServiceCategory::Dokumento->value,
            'status' => PublicationStatus::Published->value,
            'channels' => [
                ClientChannel::CitizenWeb->value,
                ClientChannel::AdminConsole->value,
            ],
        ],
        [
            'id' => '018f2c8a-0a01-7000-8000-00000000c003',
            'code' => 'REAL_PROPERTY_TAX',
            'name' => 'Real Property Tax Assessment and Payment',
            'description' => 'Assessment enquiry and payment of real property tax.',
            'category' => ServiceCategory::Buwis->value,
            'status' => PublicationStatus::Published->value,
            'channels' => [
                ClientChannel::CitizenWeb->value,
                ClientChannel::CitizenMobile->value,
                ClientChannel::AdminConsole->value,
            ],
        ],
        [
            'id' => '018f2c8a-0a01-7000-8000-00000000c004',
            'code' => 'HEALTH_CERTIFICATE',
            'name' => 'Health Certificate',
            'description' => 'Occupational health certificate issued by the municipal health office.',
            'category' => ServiceCategory::Kalusugan->value,
            'status' => PublicationStatus::Published->value,
            'channels' => [
                ClientChannel::CitizenWeb->value,
                ClientChannel::CitizenMobile->value,
                ClientChannel::AdminConsole->value,
            ],
        ],
        [
            'id' => '018f2c8a-0a01-7000-8000-00000000c005',
            'code' => 'PESO_JOB_MATCHING',
            'name' => 'PESO Job Matching',
            'description' => 'Public Employment Service Office job referral and matching.',
            'category' => ServiceCategory::Trabaho->value,
            'status' => PublicationStatus::Published->value,
            'channels' => [
                ClientChannel::CitizenWeb->value,
                ClientChannel::CitizenMobile->value,
                ClientChannel::AdminConsole->value,
            ],
        ],
        [
            'id' => '018f2c8a-0a01-7000-8000-00000000c006',
            'code' => 'LGU_RESIDENT_ID',
            'name' => 'Taytay Resident ID Application',
            'description' => 'Application, renewal and replacement of the Taytay resident ID.',
            'category' => ServiceCategory::Ids->value,
            'status' => PublicationStatus::Published->value,
            'channels' => [
                ClientChannel::CitizenWeb->value,
                ClientChannel::CitizenMobile->value,
                ClientChannel::AdminConsole->value,
            ],
        ],
        [
            'id' => '018f2c8a-0a01-7000-8000-00000000c007',
            'code' => 'NATIONAL_ID_ASSISTANCE',
            'name' => 'National ID (PhilSys) Assistance',
            'description' => 'Assisted registration and enquiry support for the national ID.',
            'category' => ServiceCategory::National->value,
            'status' => PublicationStatus::Draft->value,
            'channels' => [
                ClientChannel::AdminConsole->value,
            ],
        ],
    ],

];
