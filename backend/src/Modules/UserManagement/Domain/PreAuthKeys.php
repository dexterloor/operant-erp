<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * `API.md` §7.1 step 1 — normalise once, at the edge, **before both the lookup
 * and the limiter key**.
 *
 * ADR-006 §6.4(a) is the whole reason this is a separate class rather than two
 * `mb_strtolower()` calls at the call site: a mismatch between a
 * case-insensitive lookup and a case-sensitive limiter key lets `ACME` buy a
 * fresh bucket for `acme`, and the two normalisations have to be the *same*
 * normalisation to make that impossible. `tenant_slug_lowercase` and
 * `app_user_email_lowercase` hold the stored side; this holds the request side.
 *
 * §6.4(b) is the other half: **a single IPv6 /64 yields 2^64 source addresses**,
 * so a per-address limiter is no limiter at all. `ipPrefix()` is the /32 for
 * IPv4 and the /64 for IPv6, which is what `auth_attempt.ip_prefix` and
 * `auth_ip_budget.ip_prefix` are typed `cidr` to store.
 */
final class PreAuthKeys
{
    /** Not instantiable: three pure functions with one shared reason to exist. */
    private function __construct()
    {
    }

    public static function slug(string $workspace): string
    {
        return mb_strtolower(trim($workspace));
    }

    public static function email(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * The limiter key for a client address, as a `cidr` literal.
     *
     * An address the server cannot parse is bucketed under a single fixed
     * prefix rather than being waved through. That direction is deliberate: an
     * unparseable address is either a misconfigured proxy or a probe, and the
     * fail-open alternative hands the attacker an unmetered key by sending
     * rubbish.
     */
    public static function ipPrefix(?string $address): string
    {
        $address = null === $address ? '' : trim($address);

        if (false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return $address.'/32';
        }

        if (false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return self::slashSixtyFour($address);
        }

        // Not a routable address and deliberately not a plausible one: every
        // request whose source could not be determined shares one bucket.
        return '::/128';
    }

    /**
     * The /64 network of an IPv6 address, computed on the packed form rather
     * than on the text: `2001:db8::1` and `2001:0db8:0000:0000:0000:0000:0000:0001`
     * are the same address and a string-prefix implementation gives them
     * different buckets.
     */
    private static function slashSixtyFour(string $address): string
    {
        $packed = inet_pton($address);
        \assert(\is_string($packed) && 16 === \strlen($packed));

        $network = substr($packed, 0, 8).str_repeat("\0", 8);

        return inet_ntop($network).'/64';
    }
}
