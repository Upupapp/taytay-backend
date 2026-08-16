<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Anonymous access to the published feed
    |--------------------------------------------------------------------------
    |
    | The master command allows anonymous reading "only if Taytay explicitly marks Newsfeed
    | public", so the default is FALSE: an authenticated resident sees the feed, and nobody else
    | does until the LGU decides otherwise.
    |
    | Defaulting to true would have been the easy reading of "it is public information" — and it
    | would have published a barangay's relief schedule to the open internet before anybody at the
    | MSWDO was asked whether that was intended (gap G-36).
    |
    | Even when enabled, an anonymous reader sees municipality-wide posts only: audience targeting
    | needs a reader whose barangay is known, and there is no way to ask an anonymous caller for
    | one that is not also a way to enumerate barangays.
    |
    */

    'public_access' => (bool) env('NEWSFEED_PUBLIC', false),

];
