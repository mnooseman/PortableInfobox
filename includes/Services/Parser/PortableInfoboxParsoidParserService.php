<?php

namespace PortableInfobox\Services\Parser;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\Tidy\RemexDriver;
use MediaWiki\Title\Title;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;

/**
 * Parsoid-compatible parser service for PortableInfobox
 */
class PortableInfoboxParsoidParserService implements ExternalParser {

	protected $extApi;
	protected $tidyDriver;
	protected $cache = [];

	public function __construct( ParsoidExtensionAPI $extApi ) {
		global $wgPortableInfoboxUseTidy;

		$this->extApi = $extApi;

		if ( $wgPortableInfoboxUseTidy && class_exists( RemexDriver::class ) ) {
			$this->tidyDriver = new RemexDriver(
				new ServiceOptions(
					// @phan-suppress-next-line PhanAccessClassConstantInternal
					RemexDriver::CONSTRUCTOR_OPTIONS,
					[
						MainConfigNames::TidyConfig => [
							'driver' => 'RemexHtml',
							'pwrap' => false,
						],
					],
					// Removed in MediaWiki 1.45, so we don't use MainConfigNames here.
					// Can be removed when we drop backcompat.
					[ 'ParserEnableLegacyMediaDOM' => false ]
				)
			);
		}
	}

	/**
	 * Method used for parsing wikitext provided in infobox that might contain variables
	 * Adapted for Parsoid compatibility
	 *
	 * @param string $wikitext
	 * @return string HTML outcome
	 */
	public function parseRecursive( $wikitext ) {
		if ( isset( $this->cache[$wikitext] ) ) {
			return $this->cache[$wikitext];
		}

		// For Parsoid, we need to parse wikitext differently
		// Use simple parsing for now, can be enhanced later
		$parsed = $wikitext ?? '';
		
		// Basic variable substitution (simplified for Parsoid)
		$output = $this->replaceVariables( $parsed );
		
		if ( isset( $this->tidyDriver ) ) {
			$output = $this->tidyDriver->tidy( $output );
		}

		$newlinesstripped = preg_replace( '|[\n\r]|Us', '', $output );
		$marksstripped = preg_replace( '|{{{.*}}}|Us', '', $newlinesstripped );

		$this->cache[$wikitext] = $marksstripped;

		return $marksstripped;
	}

	public function replaceVariables( $wikitext ) {
		// For Parsoid, we handle basic variable replacement
		// More sophisticated handling can be added later
		return $wikitext;
	}

	/**
	 * Add image to parser output for later usage
	 * Adapted for Parsoid
	 *
	 * @param Title $title
	 * @param array $sizeParams
	 * @return ?string PageImages markers, if any.
	 */
	public function addImage( $title, array $sizeParams ): ?string {
		// For Parsoid, we register image usage through the extension API
		// This is a placeholder - actual implementation may vary based on Parsoid API
		
		// Return empty string for now, as Parsoid handles image processing differently
		return '';
	}
}