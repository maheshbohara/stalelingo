<?php
/**
 * Tests for Normalizer.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Unit\Domain;

use Brain\Monkey\Filters;
use Stalelingo\Domain\Normalizer;
use Stalelingo\Tests\Unit\TestCase;

/**
 * @covers \Stalelingo\Domain\Normalizer
 */
final class NormalizerTest extends TestCase {

	private Normalizer $normalizer;

	protected function set_up(): void {
		parent::set_up();
		$this->normalizer = new Normalizer();
	}

	/**
	 * Builds a parsed block the way parse_blocks() does.
	 *
	 * @param array<string, mixed>             $attrs  Attributes.
	 * @param array<int, array<string, mixed>> $inner  Inner blocks.
	 * @param list<string|null>|null           $content innerContent; defaults to [$html].
	 * @return array<string, mixed>
	 */
	private function block( ?string $name, string $html, array $attrs = array(), array $inner = array(), ?array $content = null ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $html,
			'innerContent' => $content ?? array( $html ),
		);
	}

	public function test_collapses_whitespace_and_ignores_trailing_newlines(): void {
		$this->assertSame( 'Hello world', $this->normalizer->normalize_text( "  Hello \t\n  world\n\n\n" ) );
	}

	public function test_decodes_entities_and_non_breaking_spaces(): void {
		$this->assertSame(
			'Café & “quotes” — it’s here',
			$this->normalizer->normalize_text( 'Caf&eacute; &amp; &ldquo;quotes&rdquo;&nbsp;&mdash; it&#8217;s here' )
		);
	}

	public function test_formatting_only_markup_changes_are_ignored(): void {
		$a = $this->normalizer->normalize_text( '<p>Hello <strong>world</strong></p>' );
		$b = $this->normalizer->normalize_text( '<p class="has-large-font">Hello <em>world</em></p>' );

		$this->assertSame( 'Hello world', $a );
		$this->assertSame( $a, $b );
	}

	public function test_words_in_adjacent_elements_stay_apart(): void {
		$this->assertSame( 'One Two', $this->normalizer->normalize_text( '<li>One</li><li>Two</li>' ) );
		$this->assertSame( 'Line one Line two', $this->normalizer->normalize_text( 'Line one<br>Line two' ) );
	}

	public function test_inline_tags_do_not_split_words_or_punctuation(): void {
		$this->assertSame( 'Read the docs.', $this->normalizer->normalize_text( 'Read <a href="/x">the</a> <em>docs</em>.' ) );
		$this->assertSame( 'unbelievable', $this->normalizer->normalize_text( 'un<strong>believ</strong>able' ) );
	}

	public function test_keeps_alt_and_label_text_but_drops_scripts_and_comments(): void {
		$text = $this->normalizer->normalize_text( '<img src="a.jpg" alt="A red door"><script>var x = 1;</script><!-- note --><button aria-label="Close">×</button>' );

		$this->assertStringContainsString( 'A red door', $text );
		$this->assertStringContainsString( 'Close', $text );
		$this->assertStringNotContainsString( 'var x', $text );
		$this->assertStringNotContainsString( 'note', $text );
	}

	public function test_strict_mode_keeps_markup(): void {
		$this->assertSame(
			'<p class="a">Hello world</p>',
			$this->normalizer->normalize_text( "<p class=\"a\">Hello \n world</p>\n", true )
		);
	}

	public function test_blocks_become_labelled_lines(): void {
		$blocks = array(
			$this->block( 'core/heading', '<h2 class="wp-block-heading">Title</h2>', array( 'level' => 2 ) ),
			$this->block( null, "\n\n" ),
			$this->block( 'core/paragraph', '<p>Body text.</p>' ),
		);

		$this->assertSame( "[core/heading] Title\n[core/paragraph] Body text.", $this->normalizer->normalize_blocks( $blocks ) );
	}

	public function test_block_attribute_only_changes_are_ignored(): void {
		$before = array( $this->block( 'core/paragraph', '<p>Hi</p>', array( 'align' => 'left' ) ) );
		$after  = array(
			$this->block(
				'core/paragraph',
				'<p class="has-text-align-center">Hi</p>',
				array(
					'align' => 'center',
					'style' => array( 'color' => array( 'text' => '#f00' ) ),
				)
			),
		);

		$this->assertSame( $this->normalizer->normalize_blocks( $before ), $this->normalizer->normalize_blocks( $after ) );
	}

	public function test_strict_mode_counts_block_attribute_changes(): void {
		$before = array( $this->block( 'core/paragraph', '<p>Hi</p>', array( 'align' => 'left' ) ) );
		$after  = array( $this->block( 'core/paragraph', '<p>Hi</p>', array( 'align' => 'center' ) ) );

		$this->assertNotSame( $this->normalizer->normalize_blocks( $before, true ), $this->normalizer->normalize_blocks( $after, true ) );
	}

	public function test_blocks_without_text_add_no_lines(): void {
		$blocks = array(
			$this->block( 'core/spacer', '<div style="height:40px" class="wp-block-spacer"></div>', array( 'height' => '40px' ) ),
			$this->block( 'core/separator', '<hr class="wp-block-separator"/>' ),
		);

		$this->assertSame( '', $this->normalizer->normalize_blocks( $blocks ) );
	}

	public function test_inner_blocks_are_walked_without_duplicating_text(): void {
		$inner  = $this->block( 'core/paragraph', '<p>Inside</p>' );
		$group  = $this->block(
			'core/group',
			'<div class="wp-block-group"><p>Inside</p></div>',
			array(),
			array( $inner ),
			array( '<div class="wp-block-group">', null, '</div>' )
		);
		$result = $this->normalizer->normalize_blocks( array( $group ) );

		$this->assertSame( '[core/paragraph] Inside', $result );
	}

	public function test_default_text_attributes_are_included(): void {
		$search = $this->block(
			'core/search',
			'',
			array(
				'label'      => 'Search',
				'buttonText' => 'Go',
				'width'      => 50,
			)
		);

		$this->assertSame( '[core/search] Search Go', $this->normalizer->normalize_blocks( array( $search ) ) );
	}

	public function test_text_attributes_are_filterable_and_nested_strings_are_read(): void {
		Filters\expectApplied( 'stalelingo_block_text_attributes' )->andReturnUsing(
			static fn( array $map ): array => $map + array( 'acme/card' => array( 'heading', 'items' ) )
		);
		$card = $this->block(
			'acme/card',
			'',
			array(
				'heading' => 'Hello',
				'items'   => array(
					'a' => 'One',
					'b' => array( 'Two' ),
				),
				'color'   => 'red',
			)
		);

		$this->assertSame( '[acme/card] Hello One Two', $this->normalizer->normalize_blocks( array( $card ) ) );
	}

	public function test_block_text_filter_can_add_text(): void {
		Filters\expectApplied( 'stalelingo_block_text' )->andReturnUsing(
			static fn( array $parts ): array => array_merge( $parts, array( 'extra' ) )
		);

		$this->assertSame( '[core/paragraph] Hi extra', $this->normalizer->normalize_blocks( array( $this->block( 'core/paragraph', '<p>Hi</p>' ) ) ) );
	}

	public function test_classic_content_is_one_unlabelled_line(): void {
		$this->assertSame( 'Old style post', $this->normalizer->normalize_blocks( array( $this->block( null, "<p>Old style\npost</p>" ) ) ) );
	}

	public function test_normalize_value_handles_scalars_booleans_and_arrays(): void {
		$this->assertSame( '1', $this->normalizer->normalize_value( true ) );
		$this->assertSame( '', $this->normalizer->normalize_value( false ) );
		$this->assertSame( '42', $this->normalizer->normalize_value( 42 ) );
		$this->assertSame( 'a b', $this->normalizer->normalize_value( " a \n b " ) );
		$this->assertSame(
			$this->normalizer->normalize_value(
				array(
					'b' => 2,
					'a' => array(
						'y' => 1,
						'x' => 0,
					),
				)
			),
			$this->normalizer->normalize_value(
				array(
					'a' => array(
						'x' => 0,
						'y' => 1,
					),
					'b' => 2,
				)
			)
		);
		$this->assertNotSame( $this->normalizer->normalize_value( array( 1, 2 ) ), $this->normalizer->normalize_value( array( 2, 1 ) ) );
	}
}
