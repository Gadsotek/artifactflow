<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

final class AiHarnessDriftContractTest extends TestCase
{
    public function test_shared_ai_harness_files_match_the_versioned_canonical_contract(): void
    {
        $contents = file_get_contents(base_path('harness/ai-hooks-contract.json'));
        $this->assertIsString($contents);
        $contract = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($contract);
        $this->assertSame(1, $contract['contract_version'] ?? null);
        $this->assertSame('artifactflow', $contract['canonical_project'] ?? null);

        $sharedFiles = $contract['shared_files'] ?? null;
        $this->assertIsArray($sharedFiles);
        $this->assertNotSame([], $sharedFiles);

        foreach ($sharedFiles as $path => $expectedDigest) {
            $this->assertIsString($path);
            $this->assertIsString($expectedDigest);
            $this->assertSame(
                $expectedDigest,
                hash_file('sha256', base_path($path)),
                sprintf(
                    '%s drifted from AI harness contract v%d. Update the canonical contract and peer repositories together.',
                    $path,
                    $contract['contract_version'],
                ),
            );
        }

        $projectSubstitutions = $contract['project_substitutions'] ?? null;
        $this->assertIsArray($projectSubstitutions);
        $normalizedFiles = $contract['normalized_files'] ?? null;
        $this->assertIsArray($normalizedFiles);
        $this->assertNotSame([], $normalizedFiles);

        foreach ($normalizedFiles as $path => $fileContract) {
            $this->assertIsString($path);
            $this->assertIsArray($fileContract);
            $contents = file_get_contents(base_path($path));
            $this->assertIsString($contents);
            $substitutionKeys = $fileContract['substitutions'] ?? null;
            $this->assertIsArray($substitutionKeys);

            foreach ($substitutionKeys as $substitutionKey) {
                $this->assertIsString($substitutionKey);
                $projectValue = $projectSubstitutions[$substitutionKey] ?? null;
                $this->assertIsString($projectValue);
                $this->assertStringContainsString($projectValue, $contents);
                $contents = str_replace($projectValue, sprintf('{{%s}}', $substitutionKey), $contents);
            }

            $expectedDigest = $fileContract['sha256'] ?? null;
            $this->assertIsString($expectedDigest);
            $this->assertSame(
                $expectedDigest,
                hash('sha256', $contents),
                sprintf(
                    '%s drifted from normalized AI harness contract v%d. Only declared project substitutions may differ.',
                    $path,
                    $contract['contract_version'],
                ),
            );
        }
    }
}
