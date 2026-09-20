<?php
namespace App\Core\Export;
interface ExportSource
{
    public function key(): string;
    public function authorize(object $user): void;
    /** Explicit public columns: database column => label. */
    public function columns(): array;
    public function query(object $user): \Illuminate\Database\Query\Builder;
}
