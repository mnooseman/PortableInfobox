<?php

namespace PortableInfobox\Controllers;

use PortableInfobox\Services\Helpers\InfoboxParamsValidator;
use PortableInfobox\Services\Helpers\InvalidInfoboxParamsException;
use PortableInfobox\Services\Parser\Nodes\NodeFactory;
use PortableInfobox\Services\Parser\Nodes\NodeInfobox;
use PortableInfobox\Services\Parser\Nodes\UnimplementedNodeException;
use PortableInfobox\Services\Parser\PortableInfoboxParsoidParserService;
use PortableInfobox\Services\Parser\XmlMarkupParseErrorException;
use PortableInfobox\Services\PortableInfoboxErrorRenderService;
use PortableInfobox\Services\PortableInfoboxRenderService;
use Wikimedia\Parsoid\DOM\DocumentFragment;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\Ext\ExtensionModule;
use Wikimedia\Parsoid\Ext\ExtensionTagHandler;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMUtils;

/**
 * Parsoid extension handler for PortableInfobox
 */
class PortableInfoboxParsoidHandler extends ExtensionTagHandler implements ExtensionModule {

	public const PARSER_TAG_VERSION = 2;
	public const DEFAULT_THEME_NAME = 'default';
	public const INFOBOX_THEME_PREFIX = 'pi-theme-';

	private const PARSER_TAG_NAME = 'infobox';
	private const DEFAULT_LAYOUT_NAME = 'default';
	private const INFOBOX_LAYOUT_PREFIX = 'pi-layout-';
	private const INFOBOX_TYPE_PREFIX = 'pi-type-';
	private const ACCENT_COLOR = 'accent-color';
	private const ACCENT_COLOR_TEXT = 'accent-color-text';

	private $infoboxParamsValidator = null;

	/**
	 * @return array
	 */
	public function getConfig(): array {
		return [
			'name' => 'PortableInfobox',
			'tags' => [
				[
					'name' => self::PARSER_TAG_NAME,
					'handler' => self::class,
					'options' => [
						'wt2html' => [
							'customHandler' => true,
						]
					]
				]
			]
		];
	}

	/**
	 * Parsoid handler for <infobox> tag
	 *
	 * @param ParsoidExtensionAPI $extApi
	 * @param Element $node
	 * @param array $extArgs
	 * @return DocumentFragment
	 */
	public function sourceToDom(
		ParsoidExtensionAPI $extApi,
		Element $node,
		array $extArgs
	): DocumentFragment {
		$doc = $node->ownerDocument;

		// Get the content of the infobox tag
		$content = DOMCompat::getInnerHTML( $node );
		$markup = '<' . self::PARSER_TAG_NAME . '>' . $content . '</' . self::PARSER_TAG_NAME . '>';

		// Extract attributes from extArgs
		$params = $extArgs ?? [];

		try {
			// Parse the infobox content
			$data = $this->prepareInfobox( $markup, $extApi, $params );
			
			// Get theme and other parameters
			$themeList = $this->getThemes( $params );
			$layout = $this->getLayout( $params );
			$accentColor = $this->getColor( self::ACCENT_COLOR, $params );
			$accentColorText = $this->getColor( self::ACCENT_COLOR_TEXT, $params );
			$type = $this->getType( $params );
			$itemName = $this->getItemName( $params );

			// Render the infobox
			$renderService = new PortableInfoboxRenderService();
			$html = $renderService->renderInfobox(
				$data, implode( ' ', $themeList ), $layout, $accentColor, $accentColorText, $type, $itemName
			);

			// Create DOM fragment from HTML
			$fragment = $doc->createDocumentFragment();
			if ( !empty( $html ) ) {
				DOMUtils::setInnerHTML( $fragment, $html );
			}

			// Add CSS modules
			$extApi->addModuleStyles( [ 'ext.PortableInfobox.styles' ] );
			$extApi->addModules( [ 'ext.PortableInfobox.scripts' ] );

			return $fragment;

		} catch ( UnimplementedNodeException $e ) {
			return $this->handleError( $doc, $this->getMessage( 'portable-infobox-unimplemented-infobox-tag', $e->getMessage() ) );
		} catch ( XmlMarkupParseErrorException $e ) {
			return $this->handleXmlParseError( $doc, $e->getErrors(), $content );
		} catch ( InvalidInfoboxParamsException $e ) {
			return $this->handleError( $doc, $this->getMessage( 'portable-infobox-xml-parse-error-infobox-tag-attribute-unsupported', $e->getMessage() ) );
		}
	}

	/**
	 * @param string $markup
	 * @param ParsoidExtensionAPI $extApi
	 * @param array|null $params
	 * @return array
	 * @throws UnimplementedNodeException when node used in markup does not exists
	 * @throws XmlMarkupParseErrorException xml not well formatted
	 * @throws InvalidInfoboxParamsException when unsupported attributes exist in params array
	 */
	private function prepareInfobox( $markup, ParsoidExtensionAPI $extApi, $params = null ) {
		// For Parsoid, we need to adapt the frame arguments
		$frameArguments = [];
		
		$infoboxNode = NodeFactory::newFromXML( $markup, $frameArguments ?: [] );
		
		// Set external parser (we'll need to adapt MediaWikiParserService for Parsoid)
		$infoboxNode->setExternalParser(
			new PortableInfoboxParsoidParserService( $extApi )
		);

		// Get params if not overridden
		if ( !isset( $params ) ) {
			$params = ( $infoboxNode instanceof NodeInfobox ) ? $infoboxNode->getParams() : [];
		}

		$this->getParamsValidator()->validateParams( $params );

		$data = $infoboxNode->getRenderData();

		return $data;
	}

	private function handleError( $doc, $message ): DocumentFragment {
		$fragment = $doc->createDocumentFragment();
		$errorElement = $doc->createElement( 'strong' );
		$errorElement->setAttribute( 'class', 'error' );
		$errorElement->textContent = $message;
		$fragment->appendChild( $errorElement );
		return $fragment;
	}

	private function handleXmlParseError( $doc, $errors, $xmlMarkup ): DocumentFragment {
		$errorRenderer = new PortableInfoboxErrorRenderService( $errors );
		$html = $errorRenderer->renderArticleMsgView();
		
		$fragment = $doc->createDocumentFragment();
		DOMUtils::setInnerHTML( $fragment, $html );
		
		return $fragment;
	}

	private function getThemes( $params ) {
		$themes = [];

		if ( isset( $params['theme'] ) ) {
			$staticTheme = trim( $params['theme'] );
			if ( !empty( $staticTheme ) ) {
				$themes[] = $staticTheme;
			}
		}
		if ( !empty( $params['theme-source'] ) ) {
			// For Parsoid, we'll need to handle variable themes differently
			$variableTheme = trim( $params['theme-source'] );
			if ( !empty( $variableTheme ) ) {
				$themes[] = $variableTheme;
			}
		}

		// Use default global theme if not present
		$themes = !empty( $themes ) ? $themes : [ self::DEFAULT_THEME_NAME ];

		return array_map( static function ( $name ) {
			return self::INFOBOX_THEME_PREFIX . preg_replace( '|\s+|s', '-', $name );
		}, $themes );
	}

	private function getLayout( $params ) {
		$layoutName = $params['layout'] ?? '';
		if ( $this->getParamsValidator()->validateLayout( $layoutName ) ) {
			return self::INFOBOX_LAYOUT_PREFIX . $layoutName;
		}
		return self::INFOBOX_LAYOUT_PREFIX . self::DEFAULT_LAYOUT_NAME;
	}

	private function getColor( $colorParam, $params ) {
		$sourceParam = $colorParam . '-source';
		$defaultParam = $colorParam . '-default';

		$color = '';

		// For Parsoid, handle color parameters differently
		if ( isset( $params[$sourceParam] ) ) {
			$color = trim( $params[$sourceParam] );
			$color = $this->sanitizeColor( $color );
		}

		if ( empty( $color ) && isset( $params[$defaultParam] ) ) {
			$color = trim( $params[$defaultParam] );
			$color = $this->sanitizeColor( $color );
		}

		return $color;
	}

	private function getType( $params ) {
		return !empty( $params['type'] ) ? self::INFOBOX_TYPE_PREFIX . preg_replace( '|\s+|s', '-', $params['type'] ) : '';
	}

	private function getItemName( $params ) {
		return !empty( $params['name'] ) ? $params['name'] : '';
	}

	private function sanitizeColor( $color ) {
		return $this->getParamsValidator()->validateColorValue( $color );
	}

	private function getParamsValidator() {
		if ( empty( $this->infoboxParamsValidator ) ) {
			$this->infoboxParamsValidator = new InfoboxParamsValidator();
		}
		return $this->infoboxParamsValidator;
	}

	/**
	 * Get localized message for Parsoid context
	 * 
	 * @param string $key Message key
	 * @param string $param Message parameter
	 * @return string
	 */
	private function getMessage( $key, $param = '' ) {
		// For Parsoid, we need to handle messaging differently
		// This is a simplified version - in a real implementation,
		// you might want to access the proper message system
		$messages = [
			'portable-infobox-unimplemented-infobox-tag' => "Error: Unimplemented infobox tag: $param",
			'portable-infobox-xml-parse-error-infobox-tag-attribute-unsupported' => "Error: Unsupported infobox attribute: $param",
		];
		
		return $messages[$key] ?? "Error: $key ($param)";
	}
}