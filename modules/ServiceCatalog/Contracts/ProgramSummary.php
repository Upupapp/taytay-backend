<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Contracts;

/**
 * The minimum another module may know about a programme.
 *
 * Published thin, like every other cross-module summary here. Welfare needs to know a
 * programme exists, what it is called, whether it is accepting applications and who decides
 * eligibility. It does not need the requirement templates or the guidance criteria, and handing
 * over the Eloquent model would give it both plus the ability to write them.
 */
final readonly class ProgramSummary
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public bool $acceptsApplications,
        /**
         * Whether Taytay decides who receives this.
         *
         * False for 4Ps and similar national programmes: the LGU tracks and refers, but DSWD
         * sets eligibility. Consumers must not present local guidance as a determination for
         * these (ADR 0018 §4).
         */
        public bool $locallyDetermined,
        public string $guidanceVersion,
    ) {}
}
