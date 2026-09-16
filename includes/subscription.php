<?php
/**
 * EduFlex — plans and subscription state.
 *
 * Account and Access Management, sub-module 5: Manage Subscription.
 *
 * A subscription row has existed since registration to satisfy the ERD, and
 * until now nothing read it. This file is what reads it.
 *
 * WHAT THIS IS NOT. There is no payment of any kind: no processor, no merchant
 * account, no price, and no way for a learner to give EduFlex money. Everyone
 * on the study is on the free plan, and the free plan includes every feature
 * the system has.
 *
 * WHY A PREMIUM TIER EXISTS AT ALL. The Chapter III data dictionary defines
 * plan_type as "Selected access tier, such as free or premium", so the tier is
 * part of the documented design. Patrick confirmed on 16 September 2026 that
 * premium stays in the design as future work rather than being cut. It is
 * therefore described here as designed and unavailable, which is true, rather
 * than offered, which would not be.
 *
 * The screen this feeds is a card on Settings. A panel asking "you built a tier
 * nobody can buy" has a straight answer: the tier is documented in the data
 * dictionary, the study period is free for every participant, and the screen
 * says so in the same words.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

/**
 * The plans EduFlex defines.
 *
 * `available` is the whole point of this array. A plan that is designed but not
 * offered is marked false, and every screen reads that flag rather than
 * deciding for itself, so there is one place to change when a tier becomes
 * real.
 */
const SUBSCRIPTION_PLANS = [
    'free' => [
        'label'     => 'Free',
        'available' => true,
        'summary'   => 'Everything EduFlex can do, at no cost, for as long as the study runs.',
        'includes'  => [
            'Unlimited uploaded materials',
            'Topic detection on every material',
            'Unlimited practice sets and mock examinations',
            'The AI Learning Companion',
            'Mastery tracking, growth insights and recommendations',
            'Export and delete your data at any time',
        ],
    ],
    'premium' => [
        'label'     => 'Premium',
        'available' => false,
        'summary'   => 'Designed in Chapter III and not offered. Nothing here can be purchased.',
        'includes'  => [
            'Higher AI usage limits for very large materials',
            'Longer retention of practice history',
            'Priority handling of support requests',
        ],
    ],
];

/** The plan every account is created on. */
const SUBSCRIPTION_DEFAULT_PLAN = 'free';

/** Statuses the schema allows in subscription.status. */
const SUBSCRIPTION_STATUSES = ['active', 'expired', 'cancelled'];

/* -------------------------------------------------------------------------
   Reading
   ------------------------------------------------------------------------- */

/**
 * One learner's subscription, with the plan definition attached.
 *
 * Never throws, and never returns null. A learner whose row is missing, which
 * can happen to an account created before the row was written, is reported as
 * being on the free plan, because that is what they are actually getting. A
 * billing screen that errors is worse than one that states the obvious.
 *
 * @return array{plan:string, label:string, status:string, started_at:?string,
 *               expires_at:?string, available:bool, summary:string,
 *               includes:list<string>, row_exists:bool}
 */
function subscription_for(int $userId): array
{
    $row = null;

    try {
        $stmt = db()->prepare(
            'SELECT plan_type, status, started_at, expires_at
               FROM subscription WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $found = $stmt->fetch();
        $row = $found ?: null;
    } catch (Throwable $e) {
        error_log('EduFlex subscription lookup failed: ' . $e->getMessage());
    }

    $plan = (string) ($row['plan_type'] ?? SUBSCRIPTION_DEFAULT_PLAN);
    if (!isset(SUBSCRIPTION_PLANS[$plan])) {
        // A plan name the system no longer defines. Report the free plan rather
        // than a blank screen; the learner still has every feature.
        error_log('EduFlex unknown plan_type "' . $plan . '", showing free.');
        $plan = SUBSCRIPTION_DEFAULT_PLAN;
    }

    $definition = SUBSCRIPTION_PLANS[$plan];

    return [
        'plan'       => $plan,
        'label'      => (string) $definition['label'],
        'status'     => (string) ($row['status'] ?? 'active'),
        'started_at' => $row['started_at'] ?? null,
        'expires_at' => $row['expires_at'] ?? null,
        'available'  => (bool) $definition['available'],
        'summary'    => (string) $definition['summary'],
        'includes'   => (array) $definition['includes'],
        'row_exists' => $row !== null,
    ];
}

/**
 * Create the subscription row for a new account.
 *
 * Separated from auth_register() so the plan rules live in one file. Never
 * throws: a missing subscription row must not cost somebody their registration,
 * and subscription_for() copes with its absence.
 */
function subscription_create(int $userId): bool
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO subscription (user_id, plan_type, status) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, SUBSCRIPTION_DEFAULT_PLAN, 'active']);
        return true;
    } catch (Throwable $e) {
        error_log('EduFlex subscription create failed: ' . $e->getMessage());
        return false;
    }
}

/* -------------------------------------------------------------------------
   Presentation
   ------------------------------------------------------------------------- */

function subscription_status_label(string $status): string
{
    return match ($status) {
        'active'    => 'Active',
        'expired'   => 'Expired',
        'cancelled' => 'Cancelled',
        default     => ucfirst($status),
    };
}

/** Reuse the mastery band colours rather than introduce a second palette. */
function subscription_status_band(string $status): string
{
    return match ($status) {
        'active'  => 'mastered',
        'expired' => 'weak',
        default   => 'none',
    };
}

/**
 * "Active since 16 September 2026", or a plain status when there is no date.
 */
function subscription_since(array $subscription): string
{
    $label = subscription_status_label((string) $subscription['status']);
    $start = $subscription['started_at'];

    if ($start === null || trim((string) $start) === '') {
        return $label;
    }

    $when = strtotime((string) $start);
    if ($when === false) {
        return $label;
    }

    return $label . ' since ' . date('j F Y', $when);
}

/**
 * What a learner is told about paying.
 *
 * One sentence, in one place, so the Settings card, the Terms of Service and
 * anything added later cannot end up saying three different things about
 * whether EduFlex costs money.
 */
function subscription_cost_statement(): string
{
    return 'EduFlex is a capstone research project. It is free for every participant '
         . 'for the whole of the study, it has no payment of any kind, and it will never '
         . 'ask you for money.';
}
