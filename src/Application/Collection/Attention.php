<?php

declare(strict_types=1);

namespace MamaMboga\Application\Collection;

/** Why a prompt payment is waiting for the owner instead of being recorded. None of these is ever recorded automatically. */
enum Attention: string
{
    case NeedsReview = 'needs_review';
    case LateSuccess = 'late_success';
    case CallbackQueryMismatch = 'callback_query_mismatch';
    case CallbackFieldMismatch = 'callback_field_mismatch';
    case UnverifiedSuccess = 'unverified_success';
    case PushOutcomeUnknown = 'push_outcome_unknown';
    /** The gateway first said failed, cancelled, timed out or needs review, and now says succeeded. */
    case StatusChanged = 'status_changed';
    /** The reference, amount or receipt the gateway reports does not match the request. */
    case DetailsMismatch = 'details_mismatch';
    /** The gateway says succeeded but the book refused the entry: a receipt used twice, or more than is owed. */
    case BookRefused = 'book_refused';

    public function label(): string
    {
        return match ($this) {
            self::NeedsReview => 'The gateway is holding this payment for a person to check.',
            self::LateSuccess => 'A success arrived after the deadline, so the gateway held it. Check M-Pesa before you do anything.',
            self::CallbackQueryMismatch => 'The success message and the gateway\'s own follow-up check disagree. Check M-Pesa before you do anything.',
            self::CallbackFieldMismatch => 'The success message did not match what was asked for. Check M-Pesa before you do anything.',
            self::UnverifiedSuccess => 'A success could not be verified. Check M-Pesa before you do anything.',
            self::PushOutcomeUnknown => 'The gateway never learned whether the prompt was sent.',
            self::StatusChanged => 'The gateway reported this one as not paid and now says it succeeded. The two answers disagree, so it was not recorded.',
            self::DetailsMismatch => 'The gateway\'s payment does not match this request, so it was not recorded.',
            self::BookRefused => 'The gateway says it succeeded, but the book refused the entry.',
        };
    }

    /** The gateway flag, if any, that explains a `needs_review`. Order matters: the first match wins. */
    /** @param list<string> $flags */
    public static function fromFlags(array $flags): self
    {
        foreach ([self::LateSuccess, self::CallbackQueryMismatch, self::CallbackFieldMismatch, self::UnverifiedSuccess, self::PushOutcomeUnknown] as $case) {
            if (in_array($case->value, $flags, true)) {
                return $case;
            }
        }

        return self::NeedsReview;
    }

    /** Flags that make even a `succeeded` payment unsafe to record. */
    /** @param list<string> $flags */
    public static function blockingFlag(array $flags): ?self
    {
        foreach ([self::LateSuccess, self::CallbackQueryMismatch, self::CallbackFieldMismatch, self::UnverifiedSuccess] as $case) {
            if (in_array($case->value, $flags, true)) {
                return $case;
            }
        }

        return null;
    }
}
