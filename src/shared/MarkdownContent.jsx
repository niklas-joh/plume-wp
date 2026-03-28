import { useMemo } from '@wordpress/element';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

marked.setOptions( { breaks: true, gfm: true } );

export default function MarkdownContent( { content, className } ) {
	const html = useMemo(
		() => DOMPurify.sanitize( marked.parse( content || '' ) ),
		[ content ]
	);
	return (
		<div
			className={ className }
			dangerouslySetInnerHTML={ { __html: html } }
		/>
	);
}
