<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class DcoValidatorTest extends TestCase
{
    public function test_validator_accepts_a_well_formed_signed_off_by_trailer(): void
    {
        $process = $this->validate("Subject\n\nSigned-off-by: Contributor Name <contributor@example.test>\n");

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function test_validator_rejects_missing_or_malformed_signed_off_by_trailers(): void
    {
        foreach ([
            "Subject only\n",
            "Subject\n\nSigned-off-by: Contributor <@example.test>\n",
            "Subject\n\nSigned-off-by: Contributor <contributor@example>\n",
            "Subject\n\nSigned-off-by: <contributor@example.test>\n",
            "Subject\n\nSigned-off-by: Contributor <contributor@example.test>\n\nNot a trailer block.\n",
        ] as $message) {
            $process = $this->validate($message);

            $this->assertFalse($process->isSuccessful(), $message);
            $this->assertStringContainsString('missing a valid Signed-off-by trailer', $process->getErrorOutput());
        }
    }

    private function validate(string $message): Process
    {
        $process = new Process(['sh', base_path('scripts/validate-dco.sh'), '--message-stdin']);
        $process->setInput($message);
        $process->run();

        return $process;
    }
}
