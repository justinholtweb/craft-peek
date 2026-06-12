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
}
