<?php

namespace justinholtweb\peek\tests\unit;

use Codeception\Test\Unit;
use justinholtweb\peek\enums\ReleaseStatus;

/**
 * Covers the release lifecycle state machine: which transitions are legal and
 * the presentational label/color mappings used throughout the CP.
 */
class ReleaseStatusTest extends Unit
{
    public function testEveryCaseHasLabelAndColor(): void
    {
        foreach (ReleaseStatus::cases() as $status) {
            $this->assertNotEmpty($status->label());
            $this->assertNotEmpty($status->color());
        }
    }

    public function testDraftCanMoveForward(): void
    {
        $this->assertTrue(ReleaseStatus::Draft->canTransitionTo(ReleaseStatus::Ready));
        $this->assertTrue(ReleaseStatus::Draft->canTransitionTo(ReleaseStatus::Scheduled));
        $this->assertTrue(ReleaseStatus::Draft->canTransitionTo(ReleaseStatus::Publishing));
    }

    public function testDraftCannotJumpToPublished(): void
    {
        $this->assertFalse(ReleaseStatus::Draft->canTransitionTo(ReleaseStatus::Published));
        $this->assertFalse(ReleaseStatus::Draft->canTransitionTo(ReleaseStatus::Failed));
    }

    public function testPublishingOnlyResolvesToPublishedOrFailed(): void
    {
        $this->assertTrue(ReleaseStatus::Publishing->canTransitionTo(ReleaseStatus::Published));
        $this->assertTrue(ReleaseStatus::Publishing->canTransitionTo(ReleaseStatus::Failed));
        $this->assertFalse(ReleaseStatus::Publishing->canTransitionTo(ReleaseStatus::Draft));
        $this->assertFalse(ReleaseStatus::Publishing->canTransitionTo(ReleaseStatus::Ready));
    }

    public function testPublishedIsTerminal(): void
    {
        foreach (ReleaseStatus::cases() as $target) {
            $this->assertFalse(
                ReleaseStatus::Published->canTransitionTo($target),
                "Published should not transition to {$target->value}",
            );
        }
    }

    public function testFailedCanBeRetried(): void
    {
        $this->assertTrue(ReleaseStatus::Failed->canTransitionTo(ReleaseStatus::Draft));
        $this->assertTrue(ReleaseStatus::Failed->canTransitionTo(ReleaseStatus::Ready));
        $this->assertFalse(ReleaseStatus::Failed->canTransitionTo(ReleaseStatus::Publishing));
        $this->assertFalse(ReleaseStatus::Failed->canTransitionTo(ReleaseStatus::Published));
    }

    public function testNoStatusTransitionsToItself(): void
    {
        foreach (ReleaseStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} should not transition to itself",
            );
        }
    }

    public function testFromStringRoundTrips(): void
    {
        $this->assertSame(ReleaseStatus::Scheduled, ReleaseStatus::from('scheduled'));
        $this->assertSame('published', ReleaseStatus::Published->value);
    }

    public function testTryFromReturnsNullForUnknownValue(): void
    {
        $this->assertNull(ReleaseStatus::tryFrom('nonsense'));
    }

    public function testLabelsAndColorsAreStable(): void
    {
        $this->assertSame('Draft', ReleaseStatus::Draft->label());
        $this->assertSame('grey', ReleaseStatus::Draft->color());
        $this->assertSame('Published', ReleaseStatus::Published->label());
        $this->assertSame('green', ReleaseStatus::Published->color());
        $this->assertSame('Failed', ReleaseStatus::Failed->label());
        $this->assertSame('red', ReleaseStatus::Failed->color());
    }

    /**
     * Locks down the full transition matrix so an accidental edit to one arm of
     * the state machine can't silently open (or close) a transition.
     */
    public function testFullTransitionMatrix(): void
    {
        $allowed = [
            'draft' => ['ready', 'scheduled', 'publishing'],
            'ready' => ['draft', 'scheduled', 'publishing'],
            'scheduled' => ['draft', 'ready', 'publishing'],
            'publishing' => ['published', 'failed'],
            'published' => [],
            'failed' => ['draft', 'ready'],
        ];

        foreach (ReleaseStatus::cases() as $from) {
            foreach (ReleaseStatus::cases() as $to) {
                $expected = in_array($to->value, $allowed[$from->value], true);
                $this->assertSame(
                    $expected,
                    $from->canTransitionTo($to),
                    "Transition {$from->value} -> {$to->value} should be " . ($expected ? 'allowed' : 'denied'),
                );
            }
        }
    }

    public function testOnlyPublishedIsUnpublishable(): void
    {
        foreach (ReleaseStatus::cases() as $status) {
            $this->assertSame(
                $status !== ReleaseStatus::Published,
                $status->isPublishable(),
                "{$status->value} publishability",
            );
        }
    }

    public function testFailedReleasesCanBeRetried(): void
    {
        // A failed publish is rolled back, so every draft is still there.
        $this->assertTrue(ReleaseStatus::Failed->isPublishable());
    }

    public function testPublishingStaysPublishableForTheQueuedJob(): void
    {
        // The scheduler flips a release to Publishing before pushing the job,
        // so the job itself must still be allowed to publish it.
        $this->assertTrue(ReleaseStatus::Publishing->isPublishable());
    }
}
