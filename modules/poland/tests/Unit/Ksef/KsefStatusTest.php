<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\Outgoing\KsefSubmissionState as S;
use Poland\Ksef\Outgoing\SubmissionStatus;
use Poland\Ksef\Transport\Dto\SessionInvoiceStatus;
use Poland\Tests\Support\KsefFixtures;

/** §13, §32: submitted is not accepted; every KSeF code has one local meaning; transitions are closed. */
final class KsefStatusTest extends TestCase
{
    /** @param array<string,string|null> $extensions */
    private function raw(int $code, ?string $ksefNumber = null, array $extensions = []): SessionInvoiceStatus
    {
        return new SessionInvoiceStatus(1, 'FV/1', $ksefNumber, 'REF-1', 'hash=', null, KsefFixtures::now(), null, null, null, 'Online', $code, 'opis '.$code, [], $extensions);
    }

    public function test_processing_codes_stay_processing(): void
    {
        foreach ([100, 150] as $code) {
            $status = SubmissionStatus::fromKsef($this->raw($code));
            self::assertSame(S::Processing, $status->state);
            self::assertFalse($status->isFinal());
            self::assertNull($status->ksefNumber);
        }
    }

    public function test_success_requires_a_valid_ksef_number(): void
    {
        $number = KsefFixtures::ksefNumber(7, new DateTimeImmutable('2026-09-08'), KsefFixtures::SELLER_NIP);
        $accepted = SubmissionStatus::fromKsef($this->raw(200, $number));
        self::assertSame(S::Accepted, $accepted->state);
        self::assertSame($number, $accepted->ksefNumber);

        $noNumber = SubmissionStatus::fromKsef($this->raw(200, null));
        self::assertSame(S::ManualReview, $noNumber->state);
        self::assertStringContainsString('nie zwrócił poprawnego numeru', (string) $noNumber->manualReviewReason);

        $badCrc = SubmissionStatus::fromKsef($this->raw(200, substr($number, 0, -2).'00'));
        self::assertSame(S::ManualReview, $badCrc->state);
        self::assertNull($badCrc->ksefNumber);
    }

    public function test_a_duplicate_goes_to_manual_review_with_the_original_references(): void
    {
        $status = SubmissionStatus::fromKsef($this->raw(440, null, ['originalSessionReferenceNumber' => 'S-ORIG', 'originalKsefNumber' => '5265877635-20250626-010080DD2B5E-26']));
        self::assertSame(S::ManualReview, $status->state);
        self::assertStringContainsString('DUPLIKAT', (string) $status->manualReviewReason);
        self::assertStringContainsString('5265877635-20250626-010080DD2B5E-26', (string) $status->manualReviewReason);
        self::assertSame('S-ORIG', $status->extensions['originalSessionReferenceNumber']);
    }

    public function test_the_documented_rejection_codes_are_rejections(): void
    {
        foreach ([405, 410, 415, 430, 435, 450] as $code) {
            self::assertSame(S::Rejected, SubmissionStatus::fromKsef($this->raw($code))->state, (string) $code);
        }
    }

    public function test_unknown_and_system_cancelled_codes_are_manual_review(): void
    {
        foreach ([500, 550, 999] as $code) {
            $status = SubmissionStatus::fromKsef($this->raw($code));
            self::assertSame(S::ManualReview, $status->state, (string) $code);
            self::assertNotNull($status->manualReviewReason);
        }
        self::assertStringContainsString('decyzji operatora', (string) SubmissionStatus::fromKsef($this->raw(550))->manualReviewReason);
    }

    public function test_the_state_machine_is_closed(): void
    {
        self::assertTrue(S::Draft->canTransitionTo(S::Validated));
        self::assertTrue(S::Validated->canTransitionTo(S::Ready));
        self::assertTrue(S::Ready->canTransitionTo(S::Submitted));
        self::assertTrue(S::Submitted->canTransitionTo(S::Processing));
        self::assertTrue(S::Processing->canTransitionTo(S::Accepted));
        self::assertTrue(S::Processing->canTransitionTo(S::ManualReview));
        self::assertTrue(S::ManualReview->canTransitionTo(S::Ready));

        self::assertFalse(S::Accepted->canTransitionTo(S::Processing), 'an acceptance is never undone by a poll');
        self::assertFalse(S::Accepted->canTransitionTo(S::Ready));
        self::assertFalse(S::Rejected->canTransitionTo(S::Accepted));
        self::assertFalse(S::Blocked->canTransitionTo(S::Ready), 'a blocked invoice cannot be sent');
        self::assertFalse(S::Draft->canTransitionTo(S::Submitted));
        self::assertFalse(S::Validated->canTransitionTo(S::Submitted), 'review comes before sending');
        self::assertTrue(S::Accepted->isTerminal());
        self::assertTrue(S::Rejected->isTerminal());
        self::assertTrue(S::Cancelled->isTerminal());
        self::assertFalse(S::ManualReview->isTerminal());
        self::assertTrue(S::Ready->isSendable());
        self::assertFalse(S::Validated->isSendable());

        $this->expectException(\LogicException::class);
        S::Accepted->assertTransition(S::Processing);
    }

    public function test_every_state_has_a_polish_label_and_a_stable_value(): void
    {
        foreach (S::cases() as $state) {
            self::assertNotSame('', $state->label());
            self::assertSame($state, S::from($state->value));
        }
        self::assertSame(['DRAFT', 'VALIDATED', 'BLOCKED', 'READY', 'SUBMITTED', 'PROCESSING', 'ACCEPTED', 'REJECTED', 'CANCELLED', 'MANUAL_REVIEW'], array_map(static fn (S $s): string => $s->value, S::cases()));
    }
}
