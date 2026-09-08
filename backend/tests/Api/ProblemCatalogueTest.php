<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\Problem\ProblemType;
use App\Tests\Support\ContractDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * §5.2's catalogue, reconciled with the enum that implements it.
 */
final class ProblemCatalogueTest extends TestCase
{
    /** API-13 — every `type` is a URN of the form `urn:erp:problem:<slug>`. */
    #[Test]
    public function everyTypeIsAUrnBuiltFromItsOwnSlug(): void
    {
        $cases = ProblemType::cases();
        self::assertNotSame([], $cases);

        foreach ($cases as $type) {
            self::assertSame('urn:erp:problem:'.$type->value, $type->uri());
            self::assertMatchesRegularExpression('/^[a-z]+(-[a-z]+)*$/', $type->value);
            self::assertGreaterThanOrEqual(400, $type->status());
        }
    }

    /**
     * The enum is the pin, and this is the direction that matters on a clean
     * checkout: a slug or a status changed in code with no corresponding row in
     * the document is caught here as soon as the document is present.
     */
    #[Test]
    public function theEnumIsExactlyTheDocumentsCatalogue(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent from this checkout; §5.2 has no reader here. See the phase-2 report.');
        }

        $document = ContractDocument::problemCatalogue();
        self::assertGreaterThan(10, \count($document), 'The §5.2 parser found almost nothing, so the comparison below would be vacuous.');

        $code = [];
        foreach (ProblemType::cases() as $type) {
            $code[$type->value] = $type->status();
        }

        ksort($document);
        ksort($code);

        self::assertSame($document, $code, 'The catalogue and the enum disagree, in one direction or the other.');
    }
}
