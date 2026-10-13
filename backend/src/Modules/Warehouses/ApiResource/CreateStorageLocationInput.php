<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource;

/**
 * `CreateStorageLocation`'s body — §4.2's accepted cell: `code`; `kind`. No `id` (B-3) and no `warehouse_id` (the path's):
 * sent, either is refused as undeclared (API-9). `kind` is optional — **left uninitialised** when absent, so the writer
 * omits it from the INSERT and the column default (`bin`) applies; an explicit `null` is refused. Every value is checked by
 * `StorageLocationWriter`.
 */
final class CreateStorageLocationInput
{
    public ?string $code = null;

    public ?string $kind;

    /** @return array<string, mixed> wire name => value; `kind` only when the body carried it */
    public function submitted(): array
    {
        $submitted = ['code' => $this->code];
        if ((new \ReflectionProperty($this, 'kind'))->isInitialized($this)) {
            $submitted['kind'] = $this->kind;
        }

        return $submitted;
    }
}
