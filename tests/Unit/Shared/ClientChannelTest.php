<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use Modules\Shared\Application\ClientChannel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientChannelTest extends TestCase
{
    #[Test]
    public function it_parses_the_four_supported_channels(): void
    {
        $this->assertSame(ClientChannel::CitizenWeb, ClientChannel::fromHeader('citizen-web'));
        $this->assertSame(ClientChannel::CitizenMobile, ClientChannel::fromHeader('citizen-mobile'));
        $this->assertSame(ClientChannel::AdminConsole, ClientChannel::fromHeader('admin-console'));
        $this->assertSame(ClientChannel::VerifierDevice, ClientChannel::fromHeader('verifier-device'));
    }

    #[Test]
    public function it_normalises_case_and_surrounding_whitespace(): void
    {
        $this->assertSame(ClientChannel::AdminConsole, ClientChannel::fromHeader('  Admin-Console '));
    }

    #[Test]
    public function an_absent_or_unrecognised_channel_degrades_to_unknown(): void
    {
        // Degrading rather than failing: an unparseable header is never a reason to fail
        // a citizen's request, and it grants nothing either.
        $this->assertSame(ClientChannel::Unknown, ClientChannel::fromHeader(null));
        $this->assertSame(ClientChannel::Unknown, ClientChannel::fromHeader(''));
        $this->assertSame(ClientChannel::Unknown, ClientChannel::fromHeader('root-console'));
        $this->assertSame(ClientChannel::Unknown, ClientChannel::fromHeader('admin'));
    }

    #[Test]
    public function only_the_mobile_channel_narrows_the_default_page_size(): void
    {
        $this->assertSame(15, ClientChannel::CitizenMobile->defaultPerPage());

        foreach ([ClientChannel::CitizenWeb, ClientChannel::AdminConsole, ClientChannel::VerifierDevice, ClientChannel::Unknown] as $channel) {
            $this->assertSame(25, $channel->defaultPerPage());
        }
    }
}
