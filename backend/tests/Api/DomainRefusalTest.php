<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Domain\IllegalTransition;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Tests\Support\StatusMachines;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API-15's second half: *"the API operation surfaces that refusal as
 * `business-rule-violated`"*.
 *
 * ---------------------------------------------------------------------------
 * Why this test exists before any operation can raise the exception
 * ---------------------------------------------------------------------------
 * No §4.2 operation calls a transition method yet — `ApprovePurchaseOrder`,
 * `ConfirmSalesOrder` and `PayInvoice` are all owed. So the translation cannot be
 * exercised end-to-end, and the choice was between not building it and building
 * it with a test that drives the boundary directly.
 *
 * It is built, because the alternative is a landmine: the day the first operation
 * calls `place()` is Output 9's, its author has no reason to read §8.2, and an
 * entirely correct guard would answer a legal request with a 500 — E-2's
 * business-rule branch replaced by an unhandled `\DomainException`. Tying the
 * behaviour to the artifact now is the same move §14.2 item 14 makes for D-b.
 *
 * ---------------------------------------------------------------------------
 * Dispatched, not called
 * ---------------------------------------------------------------------------
 * The first version of this test invoked `FrameworkProblemTranslator` directly.
 * `RED`: that proves the `match` arm and the base-path scope and **not** that the
 * arm wins — a listener registered above priority 0 that turns any
 * `\DomainException` into a 500, or a change in API Platform's own error-listener
 * ordering, leaves a direct call green and production answering 500. The event is
 * therefore put through the container's real dispatcher at
 * `KernelEvents::EXCEPTION`, so what is asserted is the outcome of every
 * registered listener at its real priority.
 */
final class DomainRefusalTest extends KernelTestCase
{
    /**
     * The `<table>.<rule>` code each status machine produces. The **keys** are
     * derived — a fifth status machine makes this red rather than going
     * uncovered — and the values are literal, because a table name derived from
     * the same `#[ORM\Table]` attribute the production code reads would be the
     * check comparing itself.
     *
     * @var array<class-string, string>
     */
    private const PINNED_RULE_CODES = [
        \App\Modules\Sales\Infrastructure\Entity\Invoice::class => 'invoice.status_transition_is_legal',
        \App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder::class => 'purchase_order.status_transition_is_legal',
        \App\Modules\Sales\Infrastructure\Entity\SalesOrder::class => 'sales_order.status_transition_is_legal',
        \App\Platform\Tenancy\Entity\Tenant::class => 'tenant.status_transition_is_legal',
    ];

    /**
     * **Mutation:** delete the `IllegalTransition` arm from
     * `FrameworkProblemTranslator::translate()`; the throwable is left unchanged
     * and the first assertion is red.
     */
    #[Test]
    public function anIllegalTransitionIsSurfacedAsBusinessRuleViolated(): void
    {
        $thrown = IllegalTransition::of(PurchaseOrder::class, 'place', PurchaseOrder::STATUS_RECEIVED, [PurchaseOrder::STATUS_DRAFT]);

        $problem = $this->dispatch($thrown, '/api/v1/purchase-orders/x/approval');

        self::assertInstanceOf(ApiProblem::class, $problem);
        self::assertSame('urn:erp:problem:business-rule-violated', $problem->getType());
        self::assertSame(409, $problem->getStatusCode());
        self::assertSame('purchase_order.'.IllegalTransition::RULE, $problem->getRule());
    }

    /**
     * §5.2's `detail`/`message` names the table and the statuses and **nothing
     * internal** — not the PHP class, not the method, not an ADR section.
     *
     * **Mutation:** pass `$e->getMessage()` in the translator arm instead of
     * `clientMessage()`; red — the body names `PurchaseOrder::place()`.
     */
    #[Test]
    public function theRefusalBodyNamesNoInternalIdentifier(): void
    {
        $thrown = IllegalTransition::of(PurchaseOrder::class, 'place', PurchaseOrder::STATUS_RECEIVED, [PurchaseOrder::STATUS_DRAFT]);

        $problem = $this->dispatch($thrown, '/api/v1/purchase-orders/x/approval');
        self::assertInstanceOf(ApiProblem::class, $problem);

        $detail = (string) $problem->getDetail();

        self::assertStringContainsString('purchase_order', $detail);
        self::assertStringContainsString(PurchaseOrder::STATUS_RECEIVED, $detail);
        self::assertStringNotContainsString('PurchaseOrder', $detail);
        self::assertStringNotContainsString('place', $detail);

        // `because()`'s reasons cite internal documents; none of that reaches the wire.
        $sentinel = IllegalTransition::because(\App\Platform\Tenancy\Entity\Tenant::class, 'changeStatus', 'active', 'ADR-006 §2.1 step 2\'s sentinel is fixed.');
        self::assertStringNotContainsString('ADR-006', $sentinel->clientMessage());
        self::assertStringContainsString('ADR-006', $sentinel->getMessage(), 'The developer-facing message lost the reason.');
    }

    /**
     * The rule code is derived from the entity's `#[ORM\Table]`, so every status
     * machine gets the `<table>.<rule>` form §5.2's two existing rules already use
     * rather than a name somebody typed.
     *
     * **Mutation:** hard-code the table half in `IllegalTransition::tableOf()` as
     * `'purchase_order'`; red on `invoice`. **Second mutation:** give another
     * entity a status machine; red on the derived-key assertion.
     */
    #[Test]
    public function theRuleCodeNamesTheEntitysOwnTable(): void
    {
        $pinned = array_keys(self::PINNED_RULE_CODES);
        sort($pinned);

        self::assertSame(StatusMachines::all(), $pinned, 'A status machine has no pinned rule code.');

        foreach (self::PINNED_RULE_CODES as $entity => $expected) {
            self::assertSame($expected, IllegalTransition::because($entity, 'x', 'y', 'z')->rule());
        }
    }

    /**
     * `GuardsStatusTransitions` passes `self::class`, not `static::class`, and the
     * difference is load-bearing: PHP does not inherit class attributes, and a
     * Doctrine proxy is a **subclass** of the entity. Under `static::class`,
     * `IllegalTransition::tableOf()` would find no `#[ORM\Table]` on the proxy and
     * throw `\LogicException`, turning every guarded refusal on a lazily-loaded
     * entity into a 500.
     *
     * `RED` found that nothing pinned it — the reflection rig never builds a
     * subclass — so a later *"modernise `self` to `static`"* edit would be green
     * here and broken in production. A plain subclass reproduces the attribute
     * lookup exactly, with no ORM involved.
     *
     * **Mutation:** change `self::class` to `static::class` in the trait; red with
     * `LogicException` instead of `IllegalTransition`.
     */
    #[Test]
    public function theGuardResolvesItsRuleCodeThroughASubclass(): void
    {
        $reflection = new \ReflectionClass(ProxyLikePurchaseOrder::class);
        $subject = $reflection->newInstanceWithoutConstructor();

        // Declared private on the parent, so it is reached through the declaring
        // class — which is also why a proxy cannot carry a copy of it.
        (new \ReflectionProperty(PurchaseOrder::class, 'status'))->setValue($subject, PurchaseOrder::STATUS_RECEIVED);

        self::assertSame([], $reflection->getAttributes(\Doctrine\ORM\Mapping\Table::class), 'The subclass inherited the attribute, so this test proves nothing.');

        try {
            $subject->place(new \DateTimeImmutable());
            self::fail('The guard did not refuse from "received".');
        } catch (IllegalTransition $e) {
            self::assertSame('purchase_order.'.IllegalTransition::RULE, $e->rule());
        }
    }

    /**
     * The listener stays scoped to the API base path. A domain exception raised by
     * a console command or a future non-API controller is not turned into a
     * problem+json document.
     *
     * **Mutation:** delete the `str_starts_with($request->getPathInfo(), …)` early
     * return; red.
     */
    #[Test]
    public function aDomainRefusalOutsideTheApiPathIsLeftAlone(): void
    {
        $thrown = IllegalTransition::of(PurchaseOrder::class, 'place', PurchaseOrder::STATUS_RECEIVED, [PurchaseOrder::STATUS_DRAFT]);

        self::assertSame($thrown, $this->dispatch($thrown, '/_profiler/latest'));
    }

    private function dispatch(\Throwable $thrown, string $path): \Throwable
    {
        self::bootKernel();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);

        $kernel = self::getContainer()->get('http_kernel');
        \assert($kernel instanceof HttpKernelInterface);

        $request = Request::create($path, 'POST');
        // Set for the same reason API Platform sets it: without it the listener
        // renders the response itself, which is the routing-failure path and not
        // the one an operation's refusal takes.
        $request->attributes->set('_api_resource_class', PurchaseOrder::class);

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $thrown);
        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);

        return $event->getThrowable();
    }
}

/**
 * Stands in for a Doctrine proxy: a subclass of a mapped entity, which is the
 * shape that makes `static::class` wrong. Declared here rather than generated,
 * because what is being pinned is PHP's attribute-inheritance rule and not
 * anything about the ORM.
 */
final class ProxyLikePurchaseOrder extends PurchaseOrder
{
}
