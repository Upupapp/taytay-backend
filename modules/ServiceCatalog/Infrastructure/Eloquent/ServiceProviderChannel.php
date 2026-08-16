<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * One way a referral can reach a provider.
 *
 * A row rather than an entry in a JSON array, because the channel vocabulary is closed and a
 * closed vocabulary inside a blob cannot be constrained by anything (ADR 0008 §13). It is also
 * queried: "which offices accept email" decides what this office can promise a family about how
 * fast a referral moves.
 */
final class ServiceProviderChannel extends Model
{
    protected $table = 'service_provider_channels';

    protected $guarded = ['id'];

    public $timestamps = false;
}
