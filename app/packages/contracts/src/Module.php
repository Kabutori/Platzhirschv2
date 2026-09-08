<?php
namespace App\Contracts\Module;
interface Module
{
    public function name(): string;
    public function version(): string;
    /** Exact installed versions, keyed by module code. */
    public function dependencies(): array;
    public function optionalDependencies(): array;
    public function permissions(): array;
    public function tenantMigrationsPath(): ?string;
    public function platformMigrationsPath(): ?string;
    public function tablePrefix(): string;
}
