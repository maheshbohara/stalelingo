<?php
/**
 * Tests for DriftEvaluator and Status.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Unit\Domain;

use Brain\Monkey\Filters;
use TranslationDrift\Domain\DriftEvaluator;
use TranslationDrift\Domain\Evaluation;
use TranslationDrift\Domain\Hasher;
use TranslationDrift\Domain\Status;
use TranslationDrift\Tests\Unit\TestCase;

/**
 * @covers \TranslationDrift\Domain\DriftEvaluator
 * @covers \TranslationDrift\Domain\Evaluation
 * @covers \TranslationDrift\Domain\Status
 */
final class DriftEvaluatorTest extends TestCase {

	private const BASELINE = array(
		'content' => 'c1',
		'excerpt' => 'e1',
		'meta:x'  => Hasher::ABSENT,
		'title'   => 't1',
	);

	public function test_identical_hashes_are_not_drift(): void {
		$evaluation = ( new DriftEvaluator() )->evaluate( self::BASELINE, self::BASELINE );

		$this->assertFalse( $evaluation->is_drift() );
		$this->assertSame( array(), $evaluation->changed_fields );
	}

	public function test_reports_every_changed_field(): void {
		$current = array_merge(
			self::BASELINE,
			array(
				'title'   => 't2',
				'content' => 'c2',
			)
		);

		$evaluation = ( new DriftEvaluator() )->evaluate( self::BASELINE, $current );

		$this->assertTrue( $evaluation->is_drift() );
		$this->assertSame( array( 'content', 'title' ), $evaluation->changed_fields );
	}

	public function test_a_value_added_to_or_removed_from_the_source_is_drift(): void {
		$added   = ( new DriftEvaluator() )->evaluate( self::BASELINE, array_merge( self::BASELINE, array( 'meta:x' => 'x1' ) ) );
		$removed = ( new DriftEvaluator() )->evaluate( array_merge( self::BASELINE, array( 'meta:x' => 'x1' ) ), self::BASELINE );

		$this->assertSame( array( 'meta:x' ), $added->changed_fields );
		$this->assertSame( array( 'meta:x' ), $removed->changed_fields );
	}

	public function test_newly_tracked_fields_are_reported_but_not_drift(): void {
		$evaluation = ( new DriftEvaluator() )->evaluate( self::BASELINE, array_merge( self::BASELINE, array( 'slug' => 's1' ) ) );

		$this->assertFalse( $evaluation->is_drift() );
		$this->assertSame( array( 'slug' ), $evaluation->new_fields );
	}

	public function test_fields_no_longer_tracked_are_ignored(): void {
		$current = self::BASELINE;
		unset( $current['excerpt'] );

		$this->assertFalse( ( new DriftEvaluator() )->evaluate( self::BASELINE, $current )->is_drift() );
	}

	public function test_material_change_filter_can_ignore_a_field(): void {
		Filters\expectApplied( 'tdrift_is_material_change' )
			->twice()
			->andReturnUsing( static fn( bool $material, string $field ): bool => 'excerpt' !== $field );

		$current    = array_merge(
			self::BASELINE,
			array(
				'excerpt' => 'e2',
				'title'   => 't2',
			)
		);
		$evaluation = ( new DriftEvaluator() )->evaluate( self::BASELINE, $current, array( 'translation_id' => 9 ) );

		$this->assertSame( array( 'title' ), $evaluation->changed_fields );
	}

	public function test_status_transitions(): void {
		$in_sync  = new Evaluation( array() );
		$outdated = new Evaluation( array( 'title' ) );

		$this->assertSame( Status::Missing, Status::resolve( false, false, null ) );
		$this->assertSame( Status::Untracked, Status::resolve( true, false, null ) );
		$this->assertSame( Status::InSync, Status::resolve( true, true, $in_sync ) );
		$this->assertSame( Status::Outdated, Status::resolve( true, true, $outdated ) );
		// Marked up to date again after the change.
		$this->assertSame( Status::InSync, Status::resolve( true, true, $in_sync ) );
		// A sync point without an evaluation can't be judged.
		$this->assertSame( Status::Untracked, Status::resolve( true, true, null ) );
	}
}
