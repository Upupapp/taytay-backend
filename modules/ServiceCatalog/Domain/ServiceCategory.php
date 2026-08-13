<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Domain;

/**
 * Top-level grouping of LGU services, matching the service areas the citizen clients
 * present. Values are stable strings — clients may key translations off them.
 */
enum ServiceCategory: string
{
    /** Documents and certifications. */
    case Dokumento = 'dokumento';

    /** Taxes, fees and assessments. */
    case Buwis = 'buwis';

    /** Health services. */
    case Kalusugan = 'kalusugan';

    /** Employment and livelihood. */
    case Trabaho = 'trabaho';

    /** LGU identification credentials. */
    case Ids = 'ids';

    /** Assisted access to national government services. */
    case National = 'national';
}
