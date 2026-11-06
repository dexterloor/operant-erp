<?php

declare(strict_types=1);

/**
 * `make demo-populate` (and, from C4, `make demo-walkthrough`) — the local demo workspace filled and walked through the
 * ordinary API, as the person who would do each step.
 *
 * ---------------------------------------------------------------------------
 * What it is
 * ---------------------------------------------------------------------------
 * A client of the API, not part of the application: it names no `App\` symbol, boots no kernel, opens no database and
 * reads no database credential. It speaks HTTP to an API on this machine (`make serve`), finds every path in the committed
 * `contracts/openapi.json` by `operationId`, and reads each new person's invitation from the local mail catcher (Mailpit)
 * to set their first password — the same "invitation email, then set your password" route a new colleague takes. Every
 * person and every record is therefore made by the shipped operation, under its own rules, by the person whose token made
 * the call (`demo-seed-2a-design.md` Revision 1; `demo-seed-2a-roles.md` S-1, S-2, A-1, A-2).
 *
 * ---------------------------------------------------------------------------
 * What it refuses (Revision 1 §R1-7; roles S-5, S-7; roles RED L-1)
 * ---------------------------------------------------------------------------
 *  - an `--api` or `--mailbox` that is not `http(s)://` + the literal host `127.0.0.1` or `[::1]` + an explicit port, with
 *    no user-info, query, fragment or `@` anywhere. No name is resolved; other spellings of the loopback (`127.1`,
 *    `0177.0.0.1`, `[::ffff:127.0.0.1]`, a trailing dot) are refused. `[::1]` is accepted, but `make serve` and Mailpit
 *    bind IPv4 only, so it is useful only for an API run by hand;
 *  - a redirect: the stream wrapper is told not to follow one (`follow_location => 0`), and any 3xx stops the run, because
 *    a redirect could carry a token off the machine. The stream wrapper also ignores `http_proxy`, so no proxy sees a token;
 *  - `DEMO_PASSWORD` in the process environment (the password comes from `--password-stdin`, else `.env.demo` through
 *    `Dotenv::parse()` into a variable, never into the environment);
 *  - a workspace that already holds anyone but the owner (exit 3): it never tops up;
 *  - any sign-in that is not a 200 — it stops at the first one, so a wrong password costs one failure on one account.
 *
 * Secrets: the password, bearer tokens and invitation tokens stay in memory, are spent at once, and are never printed or
 * written; there are no subprocesses, files or cookie jars. Output lines are built from a short list of fields (step,
 * person, status, problem type and rule, document number, counts) and a last filter withholds any line that looks like a
 * token. A trace never carries arguments (`zend.exception_ignore_args`); only an exception's class is printed. Mailpit is
 * only read, never written. No request body carries a `*_at` field: the server's clock decides every date.
 *
 * Exit codes: 0 done · 1 transport failure · 2 refused before any write · 3 already populated · 4 an unexpected answer
 * (step, status, problem type and rule printed) or a stop after a write. There is no automatic retry: a half-run means
 * reset the demo database and seed again.
 *
 * The classes are declared here, in their own namespace, and `main()` runs only when this file is executed directly, so a
 * test can load it (`bin/demo-seed.php`'s precedent).
 */

namespace ErpDemoWalkthrough;

use Symfony\Component\Dotenv\Dotenv;

require_once \dirname(__DIR__).'/vendor/autoload.php';

/** A stop, carrying the exit code and one line that is safe to print. */
final class Stop extends \RuntimeException
{
    public function __construct(public readonly int $exitCode, public readonly string $safeLine)
    {
        parent::__construct($safeLine);
    }
}

/** The pure refusals, unit-tested one by one (`DemoWalkthroughSafetyTest`). */
final class Safety
{
    public const LOOPBACK_HOSTS = ['127.0.0.1', '[::1]'];

    public const REFUSED_URL = 'refused: %s must be http(s)://127.0.0.1:<port> or http(s)://[::1]:<port>, on this machine, with no user name, query or fragment.';

    public const REFUSED_PASSWORD_IN_ENVIRONMENT = 'refused: DEMO_PASSWORD is set in the environment. Put it in .env.demo, or pipe it to --password-stdin, so it never reaches the environment.';

    private function __construct()
    {
    }

    /** Null when `$url` is a loopback base URL this script may talk to; the refusal line otherwise. */
    public static function refusalForUrl(string $url, string $name): ?string
    {
        $refused = \sprintf(self::REFUSED_URL, $name);
        if ('' === $url || 1 === preg_match('/[@#?\\\\\s]/', $url) || 1 !== preg_match('~^(https?)://(\[::1\]|[0-9.]+):([0-9]{1,5})/?$~', $url, $m)) {
            return $refused;
        }
        if (!\in_array($m[2], self::LOOPBACK_HOSTS, true)) {
            return $refused;
        }
        $port = (int) $m[3];
        if ($port < 1 || $port > 65535 || (string) $port !== $m[3]) {
            return $refused;
        }

        return null;
    }

    /** The URL without a trailing slash, for joining paths. */
    public static function base(string $url): string
    {
        return rtrim($url, '/');
    }

    /**
     * @param array<string, mixed> $server the process environment ($_SERVER / $_ENV / getenv())
     */
    public static function refusalForEnvironment(array $server): ?string
    {
        return \array_key_exists('DEMO_PASSWORD', $server) ? self::REFUSED_PASSWORD_IN_ENVIRONMENT : null;
    }

    /** True when a line may be printed: it carries nothing shaped like a token, a link with a token, or a bearer header. */
    public static function isPrintable(string $line): bool
    {
        return 1 !== preg_match('/#token=|Bearer\s|eyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}|[A-Za-z0-9_-]{40,}/', $line);
    }

    /**
     * The stream context options for one request. `follow_location => 0` is load-bearing: a redirect is never followed.
     *
     * @param list<string> $headers
     *
     * @return array{http: array<string, mixed>}
     */
    public static function contextOptions(string $method, array $headers, ?string $body): array
    {
        $http = [
            'method' => $method,
            'header' => implode("\r\n", [...$headers, 'Connection: close']),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 30,
            'protocol_version' => 1.1,
        ];
        if (null !== $body) {
            $http['content'] = $body;
        }

        return ['http' => $http];
    }
}

/** One HTTP exchange. A test supplies its own (the kernel browser); the script uses {@see StreamTransport}. */
interface Transport
{
    /**
     * @param list<string> $headers
     *
     * @return array{status: int, body: string}
     */
    public function send(string $method, string $url, array $headers, ?string $body): array;
}

/** PHP's stream wrapper: no subprocess, no cookie jar, no proxy, no redirect. */
final class StreamTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $context = stream_context_create(Safety::contextOptions($method, $headers, $body));
        set_error_handler(static fn (): bool => true);
        try {
            $content = file_get_contents($url, false, $context);
            $responseHeaders = http_get_last_response_headers();
        } finally {
            restore_error_handler();
        }
        // The LAST status line: were a redirect ever followed, the wrapper would list every response's headers in order,
        // and the first status line would be the redirect's, not the answer's (found by mutation M4b).
        $statusLines = array_values(array_filter(\is_array($responseHeaders) ? $responseHeaders : [], static fn (mixed $h): bool => \is_string($h) && str_starts_with($h, 'HTTP/')));
        if (false === $content || [] === $statusLines
            || 1 !== preg_match('~^HTTP/\S+\s+([0-9]{3})~', (string) end($statusLines), $m)) {
            throw new Stop(Plan::EXIT_TRANSPORT, 'transport failure: no answer from '.parse_url($url, \PHP_URL_HOST).':'.parse_url($url, \PHP_URL_PORT).' (is it running?)');
        }

        return ['status' => (int) $m[1], 'body' => $content];
    }
}

/** Where each person's invitation token comes from. */
interface Mailbox
{
    /** The token of the one invitation addressed to `$email`; a {@see Stop} when there is none, or more than one. */
    public function invitationToken(string $email): string;
}

/**
 * Mailpit's HTTP API, read only (roles S-1 change 1: the script never deletes a message; a spent token is inert, and
 * Mailpit keeps nothing across a `make reset`). Exactly one message to that exact recipient with the invitation subject
 * (`IssueInvitationHandler`), polled for up to 30 seconds.
 */
final class MailpitMailbox implements Mailbox
{
    public const SUBJECT = 'You have been added to a workspace';

    public function __construct(private readonly Transport $transport, private readonly string $base, private readonly int $waitSeconds = 30)
    {
    }

    public function ready(): bool
    {
        return 200 === $this->transport->send('GET', $this->base.'/api/v1/info', ['Accept: application/json'], null)['status'];
    }

    public function invitationToken(string $email): string
    {
        $deadline = microtime(true) + $this->waitSeconds;
        do {
            $query = http_build_query(['query' => 'to:"'.$email.'" subject:"'.self::SUBJECT.'"']);
            $answer = $this->transport->send('GET', $this->base.'/api/v1/search?'.$query, ['Accept: application/json'], null);
            $found = 200 === $answer['status'] ? json_decode($answer['body'], true) : null;
            $messages = array_values(array_filter(
                \is_array($found['messages'] ?? null) ? $found['messages'] : [],
                static fn (mixed $m): bool => \is_array($m) && self::SUBJECT === ($m['Subject'] ?? null)
                    && [$email] === array_map(static fn (mixed $to): string => strtolower((string) (\is_array($to) ? ($to['Address'] ?? '') : '')), \is_array($m['To'] ?? null) ? $m['To'] : []),
            ));
            if (\count($messages) > 1) {
                throw new Stop(Plan::EXIT_UNEXPECTED, 'more than one invitation to '.$email.' in the mail catcher: reset the demo database (make reset) and seed again.');
            }
            if (1 === \count($messages)) {
                $message = $this->transport->send('GET', $this->base.'/api/v1/message/'.rawurlencode((string) ($messages[0]['ID'] ?? '')), ['Accept: application/json'], null);
                $text = 200 === $message['status'] ? (string) (json_decode($message['body'], true)['Text'] ?? '') : '';

                return Plan::tokenIn($text) ?? throw new Stop(Plan::EXIT_UNEXPECTED, 'the invitation to '.$email.' carries no link.');
            }
            usleep(500_000);
        } while (microtime(true) < $deadline);

        throw new Stop(Plan::EXIT_UNEXPECTED, 'no invitation for '.$email.' after '.$this->waitSeconds.' s: is make worker-loop running?');
    }
}

/** The output writer: one line at a time, each through {@see Safety::isPrintable()}. */
final class Output
{
    /** @var resource */
    private $stream;

    /** @param resource|null $stream */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? \STDOUT;
    }

    public function line(string $line): void
    {
        fwrite($this->stream, (Safety::isPrintable($line) ? $line : '(a line was withheld because it looked like a secret)')."\n");
    }
}

/** A person in the story. Display names and addresses are made up (RFC 2606). */
final class Persona
{
    public function __construct(public readonly string $key, public readonly string $name, public readonly string $email)
    {
    }
}

/** The client, the people and the plan. */
final class Plan
{
    public const EXIT_OK = 0;

    public const EXIT_TRANSPORT = 1;

    public const EXIT_REFUSED = 2;

    public const EXIT_POPULATED = 3;

    public const EXIT_UNEXPECTED = 4;

    public const OWNER = 'olivia';

    /** key => [display name, email]. The owner exists already (`make demo-seed`). */
    public const PEOPLE = [
        'olivia' => ['Olivia Owner', 'owner@example.com'],
        'adam' => ['Adam Admin', 'adam.admin@example.com'],
        'bea' => ['Bea Buyer', 'bea.buyer@example.com'],
        'paul' => ['Paul Purchasing', 'paul.purchasing@example.com'],
        'wes' => ['Wes Warehouse', 'wes.warehouse@example.com'],
        'sara' => ['Sara Sales', 'sara.sales@example.com'],
        'fiona' => ['Fiona Finance', 'fiona.finance@example.com'],
        'vic' => ['Vic Viewer', 'vic.viewer@example.com'],
    ];

    /** The seeded built-in roles' display names (`bin/demo-seed.php` `Seeder::ROLE_NAMES`). */
    public const ROLE_ADMIN = 'Admin';

    public const ROLE_BUYER = 'Buyer';

    public const ROLE_PURCHASING_MANAGER = 'Purchasing manager';

    public const ROLE_WAREHOUSE_OPERATOR = 'Warehouse operator';

    public const ROLE_SALES_REP = 'Sales rep';

    public const ROLE_FINANCE = 'Finance';

    public const ROLE_VIEWER = 'Viewer';

    /** @var array<string, array{0: string, 1: string}> operationId => [method, path] */
    private array $operations;

    /** @var array<string, string> persona key => bearer token (memory only) */
    private array $tokens = [];

    /** @var array<string, string> persona key => user id */
    private array $userIds = [];

    /** @var array<string, string> role display name => role id */
    private array $roleIds = [];

    /** @var array<string, string> record label => id */
    private array $ids = [];

    private int $step = 0;

    private bool $wrote = false;

    /** @var list<string> the operationIds called, in order (tests read it) */
    public array $called = [];

    /** @var list<array<string, mixed>> every request body sent (tests read it) */
    public array $bodies = [];

    /**
     * @param array<string, mixed> $openapi the decoded `contracts/openapi.json`
     * @param \Closure(): void     $afterRedeem the wait after each first password (H-1); a test may count the calls
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $api,
        private readonly Output $output,
        private readonly string $workspace,
        #[\SensitiveParameter] private readonly string $password,
        array $openapi,
        private readonly ?\Closure $afterRedeem = null,
    ) {
        $this->operations = self::operationsIn($openapi);
    }

    /**
     * operationId => [method, path], from the spec. An operation this script names but the spec does not carry is a
     * refusal before any write (ARCH A-1), never a 404 mid-run.
     *
     * @param array<string, mixed> $openapi
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function operationsIn(array $openapi): array
    {
        $operations = [];
        foreach ((array) ($openapi['paths'] ?? []) as $path => $methods) {
            foreach ((array) $methods as $method => $operation) {
                if (\is_array($operation) && \is_string($operation['operationId'] ?? null)) {
                    $operations[$operation['operationId']] = [strtoupper((string) $method), (string) $path];
                }
            }
        }

        return $operations;
    }

    /** The invitation token in a mail body, by the suite's own pattern (`UserCreateTest`). */
    public static function tokenIn(string $text): ?string
    {
        return 1 === preg_match('~/reset-password#token=([A-Za-z0-9_-]{86})\b~', $text, $m) ? $m[1] : null;
    }

    /** @return list<string> every operationId this script may call */
    public static function operationsUsed(): array
    {
        return [
            'GetCurrentUser', 'IssueToken', 'ListUsers', 'GetUser', 'CreateUser', 'CreatePasswordResetRedemption', 'ListRoles',
            'AssignRole', 'CreateRoleGrantProposal', 'ConfirmRoleGrantProposal', 'RejectRoleGrantProposal',
            'CreateWarehouse', 'CreateStorageLocation', 'ListWarehouses', 'ListStorageLocations', 'CreateItem', 'DeleteItem', 'ListItems',
            'CreateSupplier', 'CreateSupplierProduct', 'ListSuppliers', 'CreateCustomer', 'CreateCustomerAddress', 'CreateCustomerContact',
            'ListCustomers', 'ListCustomerAddresses', 'CreatePurchaseOrder', 'ApprovePurchaseOrder', 'CancelPurchaseOrder',
            'DeletePurchaseOrder', 'ListPurchaseOrderLines', 'CreateGoodsReceipt', 'ListStockLevels', 'CreateSalesOrder',
            'ConfirmSalesOrder', 'CancelSalesOrder', 'GetPickList', 'CreateFulfilment', 'IssueInvoice', 'PayInvoice',
        ];
    }

    /** Refuses (exit 2) when the spec lacks an operation this script calls. */
    public function assertOperationsKnown(): void
    {
        $missing = array_diff(self::operationsUsed(), array_keys($this->operations));
        if ([] !== $missing) {
            throw new Stop(self::EXIT_REFUSED, 'refused: contracts/openapi.json has no operation '.implode(', ', $missing).'; is it up to date?');
        }
    }

    // -----------------------------------------------------------------------------------------------------------------
    // populate
    // -----------------------------------------------------------------------------------------------------------------

    public function fillWorkspace(Mailbox $mailbox): void
    {
        $this->assertOperationsKnown();
        $this->ready();
        $this->signIn(self::OWNER);
        $people = $this->call(self::OWNER, 'ListUsers', [], 200);
        if (\count((array) ($people['items'] ?? [])) > 1) {
            throw new Stop(self::EXIT_POPULATED, 'already populated: this workspace holds more than its owner. Reset the demo database (make reset), seed again, then populate.');
        }
        $this->userIds[self::OWNER] = (string) $this->call(self::OWNER, 'GetCurrentUser', [], 200)['id'];
        $this->loadRoles();

        // Olivia adds Adam and makes him the admin; Adam adds everyone else (roles S-2; final RED M-1's order).
        $this->onboard(self::OWNER, 'adam', $mailbox);
        $this->assign(self::OWNER, 'adam', self::ROLE_ADMIN);
        foreach (['bea', 'paul', 'wes', 'sara', 'fiona', 'vic'] as $key) {
            $this->onboard('adam', $key, $mailbox);
        }

        // Grants (§R1-2): plain roles by Adam; roles that carry an owner-only code by Olivia, or proposed and confirmed.
        $this->assign('adam', 'bea', self::ROLE_BUYER);
        $this->assign('adam', 'sara', self::ROLE_SALES_REP);
        $this->assign('adam', 'vic', self::ROLE_VIEWER);
        $this->assign(self::OWNER, 'wes', self::ROLE_WAREHOUSE_OPERATOR);
        $this->assign(self::OWNER, 'fiona', self::ROLE_FINANCE);
        $proposal = $this->call('adam', 'CreateRoleGrantProposal', ['id' => $this->userIds['paul']], 201, ['role_id' => $this->roleIds[self::ROLE_PURCHASING_MANAGER]]);
        $this->call(self::OWNER, 'ConfirmRoleGrantProposal', ['id' => (string) $proposal['id']], 200);
        $this->say('Paul has the Purchasing manager role: Adam proposed it and Olivia, the owner, confirmed it.');
        $this->call('adam', 'CreateRoleGrantProposal', ['id' => $this->userIds['vic']], 201, ['role_id' => $this->roleIds[self::ROLE_FINANCE]]);
        $this->say('Adam proposed the Finance role for Vic; it waits for the owner (it lapses after 14 days).');

        $this->records();

        $this->say(\sprintf('done: %d people, 2 warehouses with 6 locations, 8 items (1 archived), 2 suppliers, 3 customers, 4 purchase orders, 3 sales orders, 1 paid invoice.', \count(self::PEOPLE)));
    }

    private function records(): void
    {
        // Warehouses and locations (Adam).
        foreach (['MAIN' => ['Main', ['A-01' => 'bin', 'A-02' => 'bin', 'B-01' => 'bin', 'Q-01' => 'quarantine']], 'OVF' => ['Overflow', ['O-01' => 'bin', 'O-02' => 'bin']]] as $code => [$name, $locations]) {
            $warehouse = $this->call('adam', 'CreateWarehouse', [], 201, ['code' => $code, 'name' => $name, 'country_code' => 'DE']);
            $this->ids['warehouse:'.$code] = (string) $warehouse['id'];
            foreach ($locations as $location => $kind) {
                $this->ids['location:'.$location] = (string) $this->call('adam', 'CreateStorageLocation', ['id' => $warehouse['id']], 201, ['code' => $location, 'kind' => $kind])['id'];
            }
        }
        $this->say('Adam set up two warehouses, Main and Overflow, with six locations (one of them quarantine).');

        // Items (Adam): eight, one then archived so the Archived view has a row.
        foreach (self::ITEMS as $sku => $name) {
            $this->ids['item:'.$sku] = (string) $this->call('adam', 'CreateItem', [], 201, ['sku' => $sku, 'name' => $name, 'unit_of_measure_code' => 'EA'])['id'];
        }
        $this->call('adam', 'DeleteItem', ['id' => $this->ids['item:WID-008']], 204);
        $this->say('Adam added 8 items and archived one (WID-008).');

        // Suppliers (Bea): two, each with two catalogue entries.
        foreach (['SUP-001' => ['Northwind Supplies', ['WID-001' => '4.50', 'WID-002' => '7.25']], 'SUP-002' => ['Rhine Components', ['WID-003' => '12.00', 'WID-004' => '3.10']]] as $code => [$name, $products]) {
            $supplier = $this->call('bea', 'CreateSupplier', [], 201, ['code' => $code, 'name' => $name, 'country_code' => 'DE', 'currency_code' => 'EUR', 'payment_terms_days' => 30]);
            $this->ids['supplier:'.$code] = (string) $supplier['id'];
            foreach ($products as $sku => $cost) {
                $this->call('bea', 'CreateSupplierProduct', ['id' => $supplier['id']], 201, ['inventory_item_id' => $this->ids['item:'.$sku], 'unit_cost' => $cost, 'supplier_sku' => $code.'-'.$sku, 'lead_time_days' => 7, 'minimum_order_quantity' => '1']);
            }
        }
        $this->say('Bea added two suppliers, each with two catalogue entries.');

        // Customers (Sara): three, one shipping address each, one contact on the first.
        foreach (self::CUSTOMERS as $code => [$name, $line, $city, $postal]) {
            $customer = $this->call('sara', 'CreateCustomer', [], 201, ['code' => $code, 'name' => $name, 'country_code' => 'DE', 'currency_code' => 'EUR', 'payment_terms_days' => 30]);
            $this->ids['customer:'.$code] = (string) $customer['id'];
            $this->ids['address:'.$code] = (string) $this->call('sara', 'CreateCustomerAddress', ['id' => $customer['id']], 201, ['kind' => 'shipping', 'line' => $line, 'city' => $city, 'postal_code' => $postal, 'country_code' => 'DE'])['id'];
        }
        $this->call('sara', 'CreateCustomerContact', ['id' => $this->ids['customer:CUS-001']], 201, ['name' => 'Clara Contact', 'email' => 'clara.contact@example.com', 'is_primary' => true]);
        $this->say('Sara added three customers, each with a shipping address, and a contact for the first.');

        // Purchasing: PO-1 received (the stock), PO-2 approved, PO-3 draft, PO-4 cancelled.
        $po1 = $this->raisePurchaseOrder('bea', 'SUP-001', ['WID-001' => ['40', '4.50'], 'WID-002' => ['20', '7.25']]);
        $this->call('paul', 'ApprovePurchaseOrder', ['id' => $po1['id']], 200, []);
        $lines = $this->call('wes', 'ListPurchaseOrderLines', ['id' => $po1['id']], 200);
        $receipt = [];
        foreach ((array) $lines['items'] as $line) {
            $receipt[] = ['purchase_order_line_id' => (string) $line['id'], 'quantity' => (string) $line['quantity'],
                'storage_location_id' => $this->ids['WID-001' === ($line['inventory_item']['sku'] ?? null) ? 'location:A-01' : 'location:A-02']];
        }
        $this->call('wes', 'CreateGoodsReceipt', ['id' => $po1['id']], 201, ['lines' => $receipt]);
        $this->say('Bea raised '.$po1['order_number'].', Paul approved it, and Wes received 40 WID-001 into A-01 and 20 WID-002 into A-02.');
        $po2 = $this->raisePurchaseOrder('bea', 'SUP-002', ['WID-003' => ['15', '12.00']]);
        $this->call('paul', 'ApprovePurchaseOrder', ['id' => $po2['id']], 200, []);
        $po3 = $this->raisePurchaseOrder('bea', 'SUP-002', ['WID-004' => ['30', '3.10']]);
        $po4 = $this->raisePurchaseOrder('bea', 'SUP-001', ['WID-002' => ['5', '7.25']]);
        $this->call('paul', 'CancelPurchaseOrder', ['id' => $po4['id']], 200, ['reason' => 'Ordered twice by mistake.']);
        $this->say($po2['order_number'].' is approved and not yet received, '.$po3['order_number'].' is a draft, and Paul cancelled '.$po4['order_number'].'.');

        // Sales: SO-1 shipped, invoiced and paid; SO-2 confirmed and waiting to be picked; SO-3 a draft.
        $so1 = $this->raiseSalesOrder('sara', 'CUS-001', ['WID-001' => ['5', '9.90']]);
        $this->call('sara', 'ConfirmSalesOrder', ['id' => $so1['id']], 200);
        $fulfilment = $this->ship('wes', (string) $so1['id'], 'location:A-01');
        $invoice = $this->call('sara', 'IssueInvoice', ['id' => $fulfilment['id']], 201);
        $this->call('fiona', 'PayInvoice', ['id' => $invoice['id']], 200, []);
        $this->say('Sara confirmed '.$so1['order_number'].', Wes shipped it, Sara issued '.$invoice['invoice_number'].' and Fiona recorded the payment.');
        $so2 = $this->raiseSalesOrder('sara', 'CUS-002', ['WID-002' => ['3', '14.50']]);
        $this->call('sara', 'ConfirmSalesOrder', ['id' => $so2['id']], 200);
        $so3 = $this->raiseSalesOrder('sara', 'CUS-003', ['WID-001' => ['2', '9.90']]);
        $this->say($so2['order_number'].' is confirmed and waiting to be picked; '.$so3['order_number'].' is a draft.');
    }

    public const ITEMS = [
        'WID-001' => 'Widget', 'WID-002' => 'Gadget', 'WID-003' => 'Sprocket', 'WID-004' => 'Bracket',
        'WID-005' => 'Hinge', 'WID-006' => 'Fastener kit', 'WID-007' => 'Cable tie', 'WID-008' => 'Old flange',
    ];

    /** code => [name, address line, city, postal code] */
    public const CUSTOMERS = [
        'CUS-001' => ['Alder Hardware', 'Hauptstrasse 1', 'Berlin', '10115'],
        'CUS-002' => ['Birch Building Supplies', 'Marktplatz 5', 'Hamburg', '20095'],
        'CUS-003' => ['Cedar Workshop', 'Ringstrasse 12', 'Munich', '80331'],
    ];

    /**
     * @param array<string, array{0: string, 1: string}> $lines sku => [quantity, unit cost]
     *
     * @return array<string, mixed>
     */
    private function raisePurchaseOrder(string $actor, string $supplier, array $lines): array
    {
        $body = ['supplier_id' => $this->ids['supplier:'.$supplier], 'warehouse_id' => $this->ids['warehouse:MAIN'], 'currency_code' => 'EUR', 'lines' => []];
        foreach ($lines as $sku => [$quantity, $cost]) {
            $body['lines'][] = ['inventory_item_id' => $this->ids['item:'.$sku], 'quantity' => $quantity, 'unit_cost' => $cost];
        }

        return $this->call($actor, 'CreatePurchaseOrder', [], 201, $body);
    }

    /**
     * @param array<string, array{0: string, 1: string}> $lines sku => [quantity, unit price]
     *
     * @return array<string, mixed>
     */
    private function raiseSalesOrder(string $actor, string $customer, array $lines): array
    {
        $body = ['customer_id' => $this->ids['customer:'.$customer], 'ship_to_address_id' => $this->ids['address:'.$customer], 'warehouse_id' => $this->ids['warehouse:MAIN'], 'currency_code' => 'EUR', 'lines' => []];
        foreach ($lines as $sku => [$quantity, $price]) {
            $body['lines'][] = ['inventory_item_id' => $this->ids['item:'.$sku], 'quantity' => $quantity, 'unit_price' => $price];
        }

        return $this->call($actor, 'CreateSalesOrder', [], 201, $body);
    }

    /**
     * Every outstanding line of the order, from one location, as the warehouse operator reads it on the pick list.
     *
     * @return array<string, mixed>
     */
    private function ship(string $actor, string $orderId, string $location, ?string $quantity = null, int $expected = 201): array
    {
        $pick = $this->call($actor, 'GetPickList', ['id' => $orderId], 200);
        $lines = [];
        foreach ((array) $pick['lines'] as $line) {
            $lines[] = ['sales_order_line_id' => (string) $line['sales_order_line']['id'], 'quantity' => $quantity ?? (string) $line['quantity_outstanding'], 'storage_location_id' => $this->ids[$location]];
        }

        return $this->call($actor, 'CreateFulfilment', ['id' => $orderId], $expected, ['lines' => $lines]);
    }

    private function loadRoles(): void
    {
        foreach ((array) ($this->call(self::OWNER, 'ListRoles', [], 200)['items'] ?? []) as $role) {
            if (\is_array($role) && true === ($role['is_system'] ?? null)) {
                $this->roleIds[(string) $role['name']] = (string) $role['id'];
            }
        }
        foreach ([self::ROLE_ADMIN, self::ROLE_BUYER, self::ROLE_PURCHASING_MANAGER, self::ROLE_WAREHOUSE_OPERATOR, self::ROLE_SALES_REP, self::ROLE_FINANCE, self::ROLE_VIEWER] as $name) {
            if (!isset($this->roleIds[$name])) {
                throw new Stop($this->wrote ? self::EXIT_UNEXPECTED : self::EXIT_REFUSED, 'refused: the workspace has no built-in role named '.$name.'; was it seeded with make demo-seed?');
            }
        }
    }

    private function assign(string $actor, string $person, string $role): void
    {
        $this->call($actor, 'AssignRole', ['id' => $this->userIds[$person]], 201, ['role_id' => $this->roleIds[$role]]);
        $this->say(self::PEOPLE[$actor][0].' gave '.self::PEOPLE[$person][0].' the '.$role.' role.');
    }

    /**
     * One new colleague: added, invited, first password set from the invitation, then signed in — and their first
     * authenticated call must answer 200 (design H-1).
     */
    private function onboard(string $actor, string $person, Mailbox $mailbox): void
    {
        [$name, $email] = self::PEOPLE[$person];
        $this->userIds[$person] = (string) $this->call($actor, 'CreateUser', [], 201, ['email' => $email, 'display_name' => $name])['id'];
        $token = $mailbox->invitationToken($email);
        $this->call(null, 'CreatePasswordResetRedemption', [], 200, ['token' => $token, 'password' => $this->password], quiet: true);
        unset($token);
        // H-1: the redemption floors the account's session stamp to the whole second it ran in, and a token is good only
        // when issued AFTER that second (`PasswordChangeSessionTest` T-P2), so wait until the host clock has passed it. The
        // API is on this machine (`php -S` on the host), so its clock and this one are the same clock.
        ($this->afterRedeem ?? static function (): void {
            $now = microtime(true);
            usleep((int) ((floor($now) + 1.05 - $now) * 1_000_000));
        })();
        $this->signIn($person);
        if ((string) $this->call($person, 'GetCurrentUser', [], 200, label: 'first call')['id'] !== $this->userIds[$person]) {
            throw new Stop(self::EXIT_UNEXPECTED, 'first call: '.$name.' signed in as someone else.');
        }
        $this->say(self::PEOPLE[$actor][0].' added '.$name.'; the invitation set their password and they signed in.');
    }

    // -----------------------------------------------------------------------------------------------------------------
    // the client
    // -----------------------------------------------------------------------------------------------------------------

    private function ready(): void
    {
        $answer = $this->transport->send('GET', $this->api.'/api/v1/me', ['Accept: application/json'], null);
        if (401 !== $answer['status']) {
            throw new Stop(self::EXIT_REFUSED, 'refused: '.$this->api.' does not answer like this API (GET /api/v1/me without a token gave '.$answer['status'].', not 401). Is make serve running?');
        }
    }

    /** Stops at the first sign-in that is not a 200 (roles RED L-1). */
    public function signIn(string $person): void
    {
        $answer = $this->exchange(null, 'IssueToken', [], ['workspace' => $this->workspace, 'email' => self::PEOPLE[$person][1], 'password' => $this->password], []);
        if (200 !== $answer['status']) {
            $exit = $this->wrote ? self::EXIT_UNEXPECTED : self::EXIT_REFUSED;
            throw new Stop($exit, 429 === $answer['status']
                ? 'sign-in paused or budget used up for '.self::PEOPLE[$person][0].'; wait 15 minutes.'
                : 'sign-in refused for '.self::PEOPLE[$person][0].' ('.$answer['status'].'): check DEMO_PASSWORD in .env.demo and the workspace name.');
        }
        $this->tokens[$person] = (string) (json_decode($answer['body'], true)['access_token'] ?? '');
    }

    /**
     * One call that must answer `$expected`; anything else stops the run (exit 4).
     *
     * @param array<string, string>     $path
     * @param array<string, mixed>|null $body
     * @param array<string, scalar>     $query
     *
     * @return array<string, mixed>
     */
    private function call(?string $actor, string $operationId, array $path, int $expected, ?array $body = null, array $query = [], bool $quiet = false, ?string $label = null): array
    {
        $answer = $this->exchange($actor, $operationId, $path, $body, $query);
        if ($answer['status'] !== $expected) {
            throw new Stop(self::EXIT_UNEXPECTED, \sprintf('step %d (%s%s): expected %d, got %d %s', $this->step, $label ?? $operationId,
                null === $actor ? '' : ', as '.self::PEOPLE[$actor][0], $expected, $answer['status'], self::problemOf($answer['body'])));
        }
        $decoded = '' === $answer['body'] ? [] : json_decode($answer['body'], true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string>     $path
     * @param array<string, mixed>|null $body
     * @param array<string, scalar>     $query
     *
     * @return array{status: int, body: string}
     */
    private function exchange(?string $actor, string $operationId, array $path, ?array $body, array $query): array
    {
        [$method, $template] = $this->operations[$operationId] ?? throw new Stop(self::EXIT_REFUSED, 'refused: unknown operation '.$operationId);
        $url = $this->api.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => rawurlencode($path[$m[1]] ?? throw new \LogicException('No path parameter '.$m[1])), $template);
        if ([] !== $query) {
            $url .= '?'.http_build_query($query);
        }
        $headers = ['Accept: application/json'];
        if (null !== $actor) {
            $headers[] = 'Authorization: Bearer '.($this->tokens[$actor] ?? throw new \LogicException('Not signed in: '.$actor));
        }
        $content = null;
        if (null !== $body || 'POST' === $method) {
            $headers[] = 'Content-Type: application/json';
            $content = json_encode((object) ($body ?? []), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
            $this->bodies[] = $body ?? [];
        }
        if ('POST' === $method) {
            $headers[] = 'Idempotency-Key: '.self::uuid4();
        }
        ++$this->step;
        $this->called[] = $operationId;
        $answer = $this->transport->send($method, $url, $headers, $content);
        if ($answer['status'] >= 300 && $answer['status'] < 400) {
            throw new Stop(self::EXIT_UNEXPECTED, \sprintf('step %d (%s): the API answered a redirect (%d); stopped, because a redirect could carry a token off this machine.', $this->step, $operationId, $answer['status']));
        }
        if ('GET' !== $method && $answer['status'] < 300) {
            $this->wrote = true;
        }

        return $answer;
    }

    /** `type` and `rule` of a problem body, never its `detail` or anything else. */
    public static function problemOf(string $body): string
    {
        $problem = json_decode($body, true);
        if (!\is_array($problem)) {
            return '';
        }
        $type = \is_string($problem['type'] ?? null) ? $problem['type'] : '';
        $rule = \is_string($problem['rule'] ?? null) ? $problem['rule'] : '';

        return trim($type.('' === $rule ? '' : ' '.$rule));
    }

    private function say(string $line): void
    {
        $this->output->line($line);
    }

    /** A random version-4 id for the retry key, built from random bytes (no id is ever written into this file). */
    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

/** The command line. */
final class Main
{
    private function __construct()
    {
    }

    /** @param list<string> $argv */
    public static function run(array $argv, ?Transport $transport = null, ?Output $output = null): int
    {
        ini_set('zend.exception_ignore_args', '1');
        $root = \dirname(__DIR__, 2);
        $output ??= new Output();
        try {
            $command = $argv[1] ?? '';
            if ('populate' !== $command) {
                throw new Stop(Plan::EXIT_REFUSED, 'usage: demo-walkthrough.php populate --api=http://127.0.0.1:8000 --mailbox=http://127.0.0.1:<port> [--workspace=demo] [--password-stdin]');
            }
            $options = ['api' => 'http://127.0.0.1:8000', 'mailbox' => '', 'workspace' => 'demo', 'stdin' => false];
            foreach (\array_slice($argv, 2) as $argument) {
                if (1 === preg_match('/^--(api|mailbox|workspace)=(.*)$/s', $argument, $m)) {
                    $options[$m[1]] = $m[2];
                } elseif ('--password-stdin' === $argument) {
                    $options['stdin'] = true;
                } else {
                    throw new Stop(Plan::EXIT_REFUSED, 'refused: unknown argument (see usage: demo-walkthrough.php without arguments).');
                }
            }
            foreach (['api' => '--api', 'mailbox' => '--mailbox'] as $key => $name) {
                if (null !== ($refusal = Safety::refusalForUrl((string) $options[$key], $name))) {
                    throw new Stop(Plan::EXIT_REFUSED, $refusal);
                }
            }
            if (null !== ($refusal = Safety::refusalForEnvironment($_SERVER + $_ENV + getenv()))) {
                throw new Stop(Plan::EXIT_REFUSED, $refusal);
            }
            $password = self::password($root, (bool) $options['stdin']);
            $spec = json_decode((string) @file_get_contents($root.'/contracts/openapi.json'), true);
            if (!\is_array($spec)) {
                throw new Stop(Plan::EXIT_REFUSED, 'refused: cannot read contracts/openapi.json.');
            }
            $transport ??= new StreamTransport();
            $plan = new Plan($transport, Safety::base((string) $options['api']), $output, (string) $options['workspace'], $password, $spec);
            $mailbox = new MailpitMailbox($transport, Safety::base((string) $options['mailbox']));
            if (!$mailbox->ready()) {
                throw new Stop(Plan::EXIT_REFUSED, 'refused: the mail catcher does not answer at --mailbox (is make up running?).');
            }
            $plan->fillWorkspace($mailbox);

            return Plan::EXIT_OK;
        } catch (Stop $stop) {
            $output->line($stop->safeLine);

            return $stop->exitCode;
        } catch (\Throwable $e) {
            // The class only: a message or a trace could carry a value this run must not print.
            $output->line('failed ('.$e::class.'); stopped.');

            return Plan::EXIT_UNEXPECTED;
        }
    }

    private static function password(string $root, bool $fromStdin): string
    {
        if ($fromStdin) {
            $password = preg_replace('/\r?\n\z/', '', (string) stream_get_contents(\STDIN)) ?? '';
        } elseif (is_file($root.'/.env.demo')) {
            $password = (string) ((new Dotenv())->parse((string) file_get_contents($root.'/.env.demo'), $root.'/.env.demo')['DEMO_PASSWORD'] ?? '');
        } else {
            throw new Stop(Plan::EXIT_REFUSED, 'refused: no password (pipe one to --password-stdin, or cp .env.demo.example .env.demo and set DEMO_PASSWORD).');
        }
        if ('' === $password || str_starts_with($password, 'CHANGE_ME')) {
            throw new Stop(Plan::EXIT_REFUSED, 'refused: DEMO_PASSWORD is empty or still the template placeholder.');
        }

        return $password;
    }
}

if ('cli' === \PHP_SAPI && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(Main::run(array_values(array_map('strval', $_SERVER['argv'] ?? []))));
}
