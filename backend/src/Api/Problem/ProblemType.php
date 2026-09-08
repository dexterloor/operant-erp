<?php

declare(strict_types=1);

namespace App\Api\Problem;

/**
 * §5.2's catalogue, as code.
 *
 * API-13 fixes the URI form: `urn:erp:problem:<slug>`. The case names are the
 * slugs; the status is the one §5.2 declares. Nothing here is a free choice, and
 * `ProblemCatalogueTest` reconciles this enum against §5.2's table **in both
 * directions** -- a row added to the document with no case here fails, and a case
 * here with no row fails.
 *
 * The `title` is derived from the slug rather than written down a second time.
 * A hand-written title column would be a second copy of the first, drifting
 * silently, and §5.1 asks only that the title be the human-readable name of the
 * type.
 */
enum ProblemType: string
{
    case ValidationFailed = 'validation-failed';
    case BusinessRuleViolated = 'business-rule-violated';
    case PermissionDenied = 'permission-denied';
    case NotFound = 'not-found';
    case StaleRecord = 'stale-record';
    case TooManyAttempts = 'too-many-attempts';
    case SignInPaused = 'sign-in-paused';
    case AuthenticationFailed = 'authentication-failed';
    case IdempotencyKeyConflict = 'idempotency-key-conflict';
    case IdempotencyKeyRequired = 'idempotency-key-required';
    case AiCapacityExhausted = 'ai-capacity-exhausted';
    case UnsupportedMediaType = 'unsupported-media-type';
    case NotAcceptable = 'not-acceptable';
    case MethodNotAllowed = 'method-not-allowed';

    public const URN_PREFIX = 'urn:erp:problem:';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::BusinessRuleViolated, self::StaleRecord, self::IdempotencyKeyConflict => 409,
            self::PermissionDenied => 403,
            self::NotFound => 404,
            self::TooManyAttempts, self::SignInPaused => 429,
            self::AuthenticationFailed => 401,
            self::IdempotencyKeyRequired => 428,
            self::AiCapacityExhausted => 503,
            self::UnsupportedMediaType => 415,
            self::NotAcceptable => 406,
            self::MethodNotAllowed => 405,
        };
    }

    public function uri(): string
    {
        return self::URN_PREFIX.$this->value;
    }

    /** §5.1 — the human-readable name of the type, derived from the slug. */
    public function title(): string
    {
        return ucfirst(str_replace('-', ' ', $this->value));
    }
}
