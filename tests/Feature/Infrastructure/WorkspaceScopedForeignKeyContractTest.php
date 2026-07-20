<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class WorkspaceScopedForeignKeyContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_relations_between_workspace_scoped_tables_include_workspace_in_a_foreign_key(): void
    {
        $tableValues = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'workspace_uid')
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all();
        $scopedTables = [];

        foreach ($tableValues as $tableValue) {
            if (is_string($tableValue)) {
                $scopedTables[] = $tableValue;
            }
        }

        $scopedLookup = array_fill_keys($scopedTables, true);

        foreach ($scopedTables as $childTable) {
            /**
             * @var list<array{
             *     name: string,
             *     columns: list<string>,
             *     foreign_schema: string,
             *     foreign_table: string,
             *     foreign_columns: list<string>,
             *     on_update: string,
             *     on_delete: string
             * }> $foreignKeys
             */
            $foreignKeys = Schema::getForeignKeys($childTable);

            foreach ($foreignKeys as $foreignKey) {
                $parentTable = $foreignKey['foreign_table'];
                if (!isset($scopedLookup[$parentTable]) || $this->mapsWorkspace($foreignKey)) {
                    continue;
                }

                $hasWorkspaceConstraint = collect($foreignKeys)->contains(
                    static fn (array $candidate): bool => $candidate['foreign_table'] === $parentTable
                        && self::mapsWorkspace($candidate)
                        && self::containsRelation($candidate, $foreignKey),
                );

                $this->assertTrue(
                    $hasWorkspaceConstraint,
                    sprintf(
                        '%s relates two workspace-scoped tables without a composite workspace_uid foreign key. Add one or document an intentional cross-workspace relation.',
                        $childTable . '->' . $parentTable,
                    ),
                );
            }
        }
    }

    /**
     * @param array{columns: list<string>, foreign_columns: list<string>} $foreignKey
     */
    private static function mapsWorkspace(array $foreignKey): bool
    {
        $mapping = array_combine($foreignKey['columns'], $foreignKey['foreign_columns']);

        return ($mapping['workspace_uid'] ?? null) === 'workspace_uid';
    }

    /**
     * @param array{columns: list<string>, foreign_columns: list<string>} $candidate
     * @param array{columns: list<string>, foreign_columns: list<string>} $relation
     */
    private static function containsRelation(array $candidate, array $relation): bool
    {
        $candidateMapping = array_combine($candidate['columns'], $candidate['foreign_columns']);
        $relationMapping = array_combine($relation['columns'], $relation['foreign_columns']);

        foreach ($relationMapping as $childColumn => $parentColumn) {
            if (($candidateMapping[$childColumn] ?? null) !== $parentColumn) {
                return false;
            }
        }

        return true;
    }
}
