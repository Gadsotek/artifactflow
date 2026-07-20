<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

final class EloquentMassAssignmentContractTest extends TestCase
{
    public function test_every_concrete_application_model_declares_an_explicit_mass_assignment_strategy(): void
    {
        $models = $this->applicationModels();

        $this->assertNotSame([], $models);

        foreach ($models as $modelClass) {
            $reflection = new ReflectionClass($modelClass);
            $defaults = $reflection->getDefaultProperties();
            $fillableOwner = $reflection->getProperty('fillable')->getDeclaringClass()->getName();
            $guardedOwner = $reflection->getProperty('guarded')->getDeclaringClass()->getName();
            $hasApplicationFillable = !str_starts_with($fillableOwner, 'Illuminate\\');
            $hasApplicationDenyAll = !str_starts_with($guardedOwner, 'Illuminate\\')
                && ($defaults['guarded'] ?? null) === ['*'];

            $this->assertTrue(
                $hasApplicationFillable || $hasApplicationDenyAll,
                sprintf(
                    '%s must explicitly declare a $fillable allowlist or $guarded = [\'*\']. Framework defaults are not an application policy.',
                    $modelClass,
                ),
            );

            if ($hasApplicationFillable) {
                $this->assertIsArray($defaults['fillable'] ?? null, sprintf('%s::$fillable must be an array.', $modelClass));
            }
        }
    }

    /**
     * @return list<class-string<Model>>
     */
    private function applicationModels(): array
    {
        $models = [];
        $finder = new Finder()->in(app_path())->name('*.php')->files();

        foreach ($finder as $file) {
            $relativeClass = str_replace(
                [DIRECTORY_SEPARATOR, '.php'],
                ['\\', ''],
                $file->getRelativePathname(),
            );
            $candidate = 'App\\' . $relativeClass;
            if (!class_exists($candidate)) {
                continue;
            }

            $reflection = new ReflectionClass($candidate);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(Model::class)) {
                continue;
            }

            /** @var class-string<Model> $candidate */
            $models[] = $candidate;
        }

        sort($models);

        return $models;
    }
}
