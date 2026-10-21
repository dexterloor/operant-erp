<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\UserManagement\ApiResource\SettingsResource;
use App\Modules\UserManagement\Application\SettingsReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetSettings`. The tenant is the token's ({@see SettingsReader}); a tenant row gone after the gate resolved it is the
 * `/me` race and answers as `/me` does.
 *
 * @implements ProviderInterface<SettingsResource>
 */
final readonly class SettingsProvider implements ProviderInterface
{
    public function __construct(private SettingsReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SettingsResource
    {
        $view = $this->reader->read() ?? throw ApiProblem::authenticationFailed();

        return new SettingsResource($view->displayName, $view->workspace, $view->defaultCurrencyCode);
    }
}
