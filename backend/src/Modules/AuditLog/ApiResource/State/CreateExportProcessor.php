<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\AuditLog\ApiResource\CreateExportInput;
use App\Modules\AuditLog\Application\ExportRunner;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\PermissionRequirements;
use App\Platform\Security\RequestIdentity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `CreateExport` — acts ONLY on the resource the gate resolved (G5-D0 M-3, carry (1)).
 *
 * {@see PermissionRequirements::resolvedFor()} returns the code the gate checked for this request, provided the DTO's
 * `resource` is the one it was resolved from: a body read two ways is a 500, never an export under a code nobody checked
 * (`ExportContractTest::aBodyReadTwoWaysIsAServerErrorNotAnExport` plants a mismatched attribute).
 *
 * The CSV is rendered by {@see ExportRunner} after the tenancy transaction commits, into a temporary stream; this class
 * only hands that finished stream to the client, so no byte leaves before rendering completed (L-6).
 *
 * @implements ProcessorInterface<CreateExportInput, StreamedResponse>
 */
final readonly class CreateExportProcessor implements ProcessorInterface
{
    public const CONTENT_TYPE = 'text/csv; charset=utf-8';

    public function __construct(
        private ExportRunner $runner,
        private RequestIdentity $identities,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StreamedResponse
    {
        \assert($data instanceof CreateExportInput);
        $request = $context['request'] ?? null;
        if (!$request instanceof Request) {
            throw new \LogicException('CreateExport runs only on an HTTP request.');
        }

        // The gate refused a non-string `resource` before the 403, so a non-string here means the body was read two ways.
        $resource = \is_string($data->resource) ? $data->resource : '';
        PermissionRequirements::resolvedFor($request, $resource);

        if (null !== $data->sort && !\is_string($data->sort)) {
            throw ApiProblem::validationFailed([['pointer' => '/sort', 'code' => 'not_a_string', 'message' => 'sort must be given as text.']]);
        }

        $export = $this->runner->run($resource, $data->filters, $data->sort, $this->identities->authenticated(), $request->getClientIp());
        $stream = $export['stream'];

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => self::CONTENT_TYPE,
            'Content-Disposition' => \sprintf('attachment; filename="%s"', self::filename($resource)),
        ]);
    }

    /** `<resource>-<YYYYMMDD>.csv`, the UTC date (design §6.2; `UX` to confirm the name). */
    public static function filename(string $resource): string
    {
        return \sprintf('%s-%s.csv', $resource, gmdate('Ymd'));
    }
}
