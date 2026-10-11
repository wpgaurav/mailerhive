( function () {
	'use strict';

	const VERSION = '0.2.0';
	const STORE_NAME = 'mailerpress';
	const EDITABLE_BLOCKS = '.node-type-text, .node-type-heading, .node-type-list-items';
	const DROP_ELEMENTS = new Set( [
		'BASE', 'CANVAS', 'EMBED', 'FORM', 'IFRAME', 'INPUT', 'LINK', 'META',
		'NOSCRIPT', 'OBJECT', 'SCRIPT', 'SELECT', 'STYLE', 'SVG', 'TEMPLATE',
		'TEXTAREA', 'VIDEO',
	] );
	const ALLOWED_ELEMENTS = new Set( [
		'A', 'BLOCKQUOTE', 'BR', 'CODE', 'DEL', 'DIV', 'EM', 'H1', 'H2', 'H3',
		'H4', 'H5', 'H6', 'HR', 'LI', 'OL', 'P', 'PRE', 'S', 'SPAN', 'STRONG',
		'SUB', 'SUP', 'U', 'UL',
	] );
	const SEMANTIC_SELECTOR = [
		'.wp-block-button', '.wp-block-spacer', 'h1', 'h2', 'h3', 'h4', 'h5',
		'h6', 'p', 'ul', 'ol', 'blockquote', 'pre', 'hr', 'table', 'figure', 'img',
	].join( ',' );
	const DEFAULT_CONTENT = new Set( [
		'', 'Make it easy to compose email', 'Heading title',
		'<ul><li>First item</li></ul>',
	] );

	function createClientId() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}

		return `gt-${ Date.now().toString( 36 ) }-${ Math.random().toString( 36 ).slice( 2 ) }`;
	}

	function sanitizeHref( value ) {
		const href = String( value || '' ).trim();

		if ( ! href ) {
			return '';
		}

		if ( href.startsWith( '#' ) || /^%(?:[A-Z0-9_]+)%$/.test( href ) ) {
			return href;
		}

		try {
			const parsed = new URL( href, window.location.origin );
			return [ 'http:', 'https:', 'mailto:', 'tel:' ].includes( parsed.protocol ) ? parsed.href : '';
		} catch ( error ) {
			return '';
		}
	}

	function sanitizeImageSource( value ) {
		const source = sanitizeHref( value );

		if ( ! /^https?:/i.test( source ) || /\.svg(?:\?|#|$)/i.test( source ) ) {
			return '';
		}

		return source;
	}

	function safeColor( value ) {
		const color = String( value || '' ).trim();

		if ( ! color || /(?:url|var|expression)\s*\(/i.test( color ) ) {
			return '';
		}

		return window.CSS && window.CSS.supports( 'color', color ) ? color : '';
	}

	function styleMarks( element ) {
		const style = element.style;
		const weight = style.fontWeight || '';
		const decoration = `${ style.textDecoration || '' } ${ style.textDecorationLine || '' }`;

		return {
			bold: /^(?:bold|bolder)$/i.test( weight ) || /^([6-9]00)$/.test( weight ),
			italic: /italic|oblique/i.test( style.fontStyle || '' ),
			underline: /underline/i.test( decoration ),
			strike: /line-through/i.test( decoration ),
			color: safeColor( style.color ),
			backgroundColor: safeColor( style.backgroundColor ),
		};
	}

	function cleanNode( source, outputDocument ) {
		if ( source.nodeType === Node.TEXT_NODE ) {
			return outputDocument.createTextNode( source.nodeValue || '' );
		}

		if ( source.nodeType !== Node.ELEMENT_NODE ) {
			return outputDocument.createDocumentFragment();
		}

		if ( DROP_ELEMENTS.has( source.tagName ) ) {
			return outputDocument.createDocumentFragment();
		}

		const tagName = source.tagName === 'B'
			? 'STRONG'
			: source.tagName === 'I'
				? 'EM'
				: source.tagName === 'STRIKE'
					? 'S'
					: source.tagName === 'MARK'
						? 'SPAN'
						: source.tagName;
		const element = ALLOWED_ELEMENTS.has( tagName )
			? outputDocument.createElement( tagName.toLowerCase() )
			: outputDocument.createDocumentFragment();

		Array.from( source.childNodes ).forEach( ( child ) => {
			element.appendChild( cleanNode( child, outputDocument ) );
		} );

		if ( element.nodeType === Node.ELEMENT_NODE && tagName === 'A' ) {
			const href = sanitizeHref( source.getAttribute( 'href' ) );

			if ( href ) {
				element.setAttribute( 'href', href );
				const title = source.getAttribute( 'title' );
				const target = source.getAttribute( 'target' );

				if ( title ) {
					element.setAttribute( 'title', title.trim().slice( 0, 300 ) );
				}
				if ( target === '_blank' ) {
					element.setAttribute( 'target', '_blank' );
					element.setAttribute( 'rel', 'noopener noreferrer' );
				}
			} else {
				const fragment = outputDocument.createDocumentFragment();
				fragment.append( ...Array.from( element.childNodes ) );
				return fragment;
			}
		}

		const marks = styleMarks( source );
		let result = element;

		if ( marks.color || marks.backgroundColor ) {
			let styledElement = element;

			if ( element.nodeType !== Node.ELEMENT_NODE ) {
				const span = outputDocument.createElement( 'span' );
				span.appendChild( result );
				result = span;
				styledElement = span;
			}

			const declarations = [];
			if ( marks.color ) {
				declarations.push( `color: ${ marks.color }` );
			}
			if ( marks.backgroundColor ) {
				declarations.push( `background-color: ${ marks.backgroundColor }` );
			}
			styledElement.setAttribute( 'style', declarations.join( '; ' ) );
		}

		[
			[ marks.strike && ! [ 'S', 'DEL' ].includes( tagName ), 's' ],
			[ marks.underline && tagName !== 'U', 'u' ],
			[ marks.italic && tagName !== 'EM', 'em' ],
			[ marks.bold && tagName !== 'STRONG', 'strong' ],
		].forEach( ( [ active, wrapperTag ] ) => {
			if ( active ) {
				const wrapper = outputDocument.createElement( wrapperTag );
				wrapper.appendChild( result );
				result = wrapper;
			}
		} );

		return result;
	}

	function sanitize( html ) {
		const sourceDocument = new DOMParser().parseFromString( String( html || '' ), 'text/html' );
		const outputDocument = document.implementation.createHTMLDocument( '' );
		const container = outputDocument.createElement( 'div' );

		Array.from( sourceDocument.body.childNodes ).forEach( ( child ) => {
			container.appendChild( cleanNode( child, outputDocument ) );
		} );

		return container.innerHTML.trim();
	}

	function makeLeaf( type, content, attributes = {}, data = {} ) {
		return {
			type,
			data: { content, ...data },
			attributes: {
				'padding-top': '10px',
				'padding-right': '25px',
				'padding-bottom': '10px',
				'padding-left': '25px',
				...attributes,
			},
			children: [],
			clientId: createClientId(),
		};
	}

	function makeHeading( element ) {
		const depth = Number( element.tagName.slice( 1 ) ) || 2;
		const content = sanitize( element.innerHTML );

		return content ? [ makeLeaf( 'heading', content, { 'mj-class': 'heading' }, { level: depth } ) ] : [];
	}

	function makeText( element ) {
		const html = element.tagName === 'DIV'
			? `<p>${ sanitize( element.innerHTML ) }</p>`
			: sanitize( element.outerHTML );

		return html && element.textContent.trim() ? [ makeLeaf( 'text', html ) ] : [];
	}

	function makeList( element ) {
		const html = sanitize( element.outerHTML );
		return html && element.textContent.trim() ? [ makeLeaf( 'list-items', html, { align: 'left' } ) ] : [];
	}

	function makeQuote( element ) {
		const content = sanitize( element.innerHTML );
		return content && element.textContent.trim()
			? [ makeLeaf( 'text', `<blockquote>${ content }</blockquote>` ) ]
			: [];
	}

	function makeDivider() {
		return [ {
			type: 'divider',
			data: {},
			attributes: {
				align: 'center',
				width: '100%',
				'border-width': '1px',
				'border-style': 'solid',
				'border-color': '#d1d5db',
				'padding-top': '10px',
				'padding-right': '10px',
				'padding-bottom': '10px',
				'padding-left': '10px',
			},
			children: [],
			clientId: createClientId(),
		} ];
	}

	function makeSpacer( element ) {
		const rawHeight = parseInt( element.style.height || element.getAttribute( 'height' ) || '20', 10 );
		const height = Math.max( 4, Math.min( Number.isFinite( rawHeight ) ? rawHeight : 20, 200 ) );

		return [ {
			type: 'spacer',
			data: {},
			attributes: { height: `${ height }px` },
			children: [],
			clientId: createClientId(),
		} ];
	}

	function makeButton( element ) {
		const anchor = element.matches( 'a' ) ? element : element.querySelector( 'a[href]' );
		const href = anchor && sanitizeHref( anchor.getAttribute( 'href' ) );
		const content = anchor && sanitize( anchor.innerHTML );

		if ( ! href || ! content || ! anchor.textContent.trim() ) {
			return makeText( element );
		}

		return [ makeLeaf( 'button', content, {
			align: /has-text-align-(left|right)/.test( element.className )
				? element.className.match( /has-text-align-(left|right)/ )[ 1 ]
				: 'center',
			'border-radius': '4px',
			href,
			...( anchor.target === '_blank' ? { target: '_blank' } : {} ),
		}, { 'border-style': 'solid' } ) ];
	}

	function makeImage( element ) {
		const image = element.matches( 'img' ) ? element : element.querySelector( 'img' );
		const source = image && sanitizeImageSource( image.currentSrc || image.getAttribute( 'src' ) );

		if ( ! source ) {
			return [];
		}

		const link = image.closest( 'a[href]' );
		const href = link ? sanitizeHref( link.getAttribute( 'href' ) ) : '';
		const rawWidth = parseInt( image.getAttribute( 'width' ) || image.style.width || '1024', 10 );
		const width = Math.max( 1, Math.min( Number.isFinite( rawWidth ) ? rawWidth : 1024, 1200 ) );
		const blocks = [ {
			type: 'image',
			data: { width, size: 'full' },
			attributes: {
				width: `${ width }px`,
				align: 'center',
				src: source,
				href,
				alt: String( image.getAttribute( 'alt' ) || '' ).trim().slice( 0, 500 ),
				'fluid-on-mobile': true,
			},
			children: [],
			clientId: createClientId(),
		} ];
		const caption = element.matches( 'figure' ) && element.querySelector( 'figcaption' );

		if ( caption && caption.textContent.trim() ) {
			blocks.push( makeLeaf( 'text', `<p><em>${ sanitize( caption.innerHTML ) }</em></p>`, {
				align: 'center',
				'font-size': '13px',
				'padding-top': '4px',
			} ) );
		}

		return blocks;
	}

	function makeTable( element ) {
		const rows = Array.from( element.querySelectorAll( 'tr' ) ).slice( 0, 100 );
		const content = rows.map( ( row ) => {
			const cells = Array.from( row.children )
				.filter( ( cell ) => [ 'TD', 'TH' ].includes( cell.tagName ) )
				.slice( 0, 20 )
				.map( ( cell ) => {
					const tag = cell.tagName.toLowerCase();
					const colspan = Math.max( 1, Math.min( parseInt( cell.getAttribute( 'colspan' ) || '1', 10 ), 20 ) );
					const span = colspan > 1 ? ` colspan="${ colspan }"` : '';
					const weight = tag === 'th' ? ' font-weight: 700;' : '';
					return `<${ tag }${ span } style="padding:10px 8px; border-bottom:1px solid #e5e7eb; text-align:left;${ weight }">${ sanitize( cell.innerHTML ) }</${ tag }>`;
				} ).join( '' );

			return cells ? `<tr>${ cells }</tr>` : '';
		} ).filter( Boolean ).join( '\n' );

		return content ? [ {
			type: 'table',
			data: { content },
			attributes: {},
			children: [],
			clientId: createClientId(),
		} ] : [];
	}

	function convertElement( element ) {
		if ( element.matches( '.wp-block-button, [data-type="core/button"]' ) ) return makeButton( element );
		if ( element.matches( '.wp-block-spacer' ) ) return makeSpacer( element );
		if ( /^H[1-6]$/.test( element.tagName ) ) return makeHeading( element );
		if ( [ 'UL', 'OL' ].includes( element.tagName ) ) return makeList( element );
		if ( element.tagName === 'BLOCKQUOTE' ) return makeQuote( element );
		if ( element.tagName === 'HR' ) return makeDivider();
		if ( element.tagName === 'TABLE' ) return makeTable( element );
		if ( element.tagName === 'FIGURE' || element.tagName === 'IMG' || element.querySelector( ':scope > img, :scope > a > img' ) ) return makeImage( element );
		return makeText( element );
	}

	function collectSemanticElements( sourceDocument ) {
		const candidates = Array.from( sourceDocument.body.querySelectorAll( SEMANTIC_SELECTOR ) );
		const leafDivs = Array.from( sourceDocument.body.querySelectorAll( 'div' ) ).filter( ( element ) =>
			! element.querySelector( SEMANTIC_SELECTOR ) && element.textContent.trim()
		);
		const all = [ ...new Set( [ ...candidates, ...leafDivs ] ) ].filter( ( element ) =>
			! element.parentElement?.closest( SEMANTIC_SELECTOR )
		);

		return all.sort( ( left, right ) =>
			left === right
				? 0
				: left.compareDocumentPosition( right ) & Node.DOCUMENT_POSITION_FOLLOWING
					? -1
					: 1
		);
	}

	function convert( html ) {
		const sourceDocument = new DOMParser().parseFromString( String( html || '' ), 'text/html' );
		const elements = collectSemanticElements( sourceDocument );
		const blocks = elements.flatMap( convertElement );

		if ( blocks.length ) {
			return blocks;
		}

		const fallback = sanitize( sourceDocument.body.innerHTML );
		return fallback && sourceDocument.body.textContent.trim() ? [ makeLeaf( 'text', fallback ) ] : [];
	}

	function findSupportedEditable( target ) {
		if ( ! ( target instanceof Element ) ) {
			return null;
		}

		const editable = target.closest( '[contenteditable="true"]' );
		const block = editable && editable.closest( EDITABLE_BLOCKS );

		return block ? { editable, block } : null;
	}

	function findTreeEntry( node, clientId, parent = null ) {
		if ( node && node.clientId === clientId ) {
			return { node, parent };
		}

		for ( const child of node?.children || [] ) {
			const found = findTreeEntry( child, clientId, node );
			if ( found ) return found;
		}

		return null;
	}

	function findDomTreeEntry( tree, blockElement ) {
		const typeClass = Array.from( blockElement.classList ).find( ( name ) => name.startsWith( 'node-type-' ) );
		const type = typeClass ? typeClass.slice( 'node-type-'.length ) : '';
		const clientIds = Array.from( blockElement.classList )
			.filter( ( name ) => name.startsWith( 'node-client-' ) )
			.map( ( name ) => name.slice( 'node-client-'.length ) )
			.filter( ( value ) => value && ! [ 'undefined', 'null' ].includes( value ) );

		for ( const id of clientIds ) {
			const entry = findTreeEntry( tree, id );
			if ( entry && ( ! type || entry.node.type === type ) ) return entry;
		}

		return null;
	}

	function selectionCoversEditable( editable ) {
		const selection = window.getSelection();
		if ( ! selection || selection.rangeCount === 0 ) return false;

		const selected = selection.toString().replace( /\s+/g, ' ' ).trim();
		const complete = editable.innerText.replace( /\s+/g, ' ' ).trim();
		return complete !== '' && selected === complete;
	}

	function blockIsReplaceable( block, editable ) {
		const content = String( block?.data?.content || '' ).replace( /\s+/g, ' ' ).trim();
		const plain = content.replace( /<[^>]*>/g, '' ).replace( /&nbsp;/g, ' ' ).trim();

		return DEFAULT_CONTENT.has( content ) || DEFAULT_CONTENT.has( plain ) || selectionCoversEditable( editable );
	}

	function insertNativeBlocks( blockElement, editable, blocks ) {
		const dataApi = window.wp && window.wp.data;
		const selector = dataApi && dataApi.select( STORE_NAME );
		const dispatcher = dataApi && dataApi.dispatch( STORE_NAME );

		if ( ! selector?.getBlocks || ! dispatcher?.addBlock || ! blocks.length ) return false;

		const tree = selector.getBlocks();
		const entry = findDomTreeEntry( tree, blockElement );
		if ( ! entry?.parent || ! blockIsReplaceable( entry.node, editable ) ) return false;

		const clone = typeof window.structuredClone === 'function'
			? window.structuredClone( tree )
			: JSON.parse( JSON.stringify( tree ) );
		const cloneEntry = findTreeEntry( clone, entry.node.clientId );
		const index = cloneEntry?.parent?.children?.findIndex( ( child ) => child.clientId === cloneEntry.node.clientId );

		if ( ! cloneEntry?.parent || index < 0 ) return false;

		blocks[ 0 ].clientId = cloneEntry.node.clientId;
		cloneEntry.parent.children.splice( index, 1, ...blocks );
		dispatcher.addBlock( clone );
		dispatcher.selectBlock?.( blocks[ 0 ].clientId );
		return true;
	}

	function insertInline( editable, html ) {
		document.execCommand( 'insertHTML', false, html );
		editable.dispatchEvent( new InputEvent( 'input', {
			bubbles: true,
			inputType: 'insertFromPaste',
		} ) );
	}

	function onPaste( event ) {
		const target = findSupportedEditable( event.target );
		const clipboardHtml = event.clipboardData && event.clipboardData.getData( 'text/html' );

		if ( ! target || ! clipboardHtml ) {
			return;
		}

		const blocks = convert( clipboardHtml );
		const safeHtml = sanitize( clipboardHtml );

		if ( ! blocks.length && ! safeHtml ) {
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();

		if ( ! insertNativeBlocks( target.block, target.editable, blocks ) ) {
			insertInline( target.editable, safeHtml );
		}
	}

	document.documentElement.dataset.gtMailerPressRichPaste = VERSION;
	window.GTMailerPressRichPaste = Object.freeze( { sanitize, convert, version: VERSION } );
	document.addEventListener( 'paste', onPaste, false );
}() );
