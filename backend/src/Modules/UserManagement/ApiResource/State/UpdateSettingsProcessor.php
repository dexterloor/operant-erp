<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\UserManagement\ApiResource\SettingsResource;
use App\Modules\UserManagement\ApiResource\UpdateSettingsInput;
use App\Modules\UserManagement\Application\SettingsWriter;

/**
 * `UpdateSettings` — `PATCH /api/v1/settings`, body `{display_name}` (G7b). **200**, `GetSettings`' body in the new state; a
 * request that changes nothing is 200 with no write. Declared `read: false`: nothing is read before the settings row lock.
 *
 * @implements ProcessorInterface<UpdateSettingsInput, SettingsResource>
 */
final readonly class UpdateSettingsProcessor implements ProcessorInterface
{
    public function __construct(private SettingsWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SettingsResource
    {
        \assert($data instanceof UpdateSettingsInput);
        $view = $this->writer->rename($data->submitted());

        return new SettingsResource($view->displayName, $view->workspace, $view->defaultCurrencyCode);
    }
}
