import PostListTable from '../shared/PostListTable';
import ImagesBadge from './ImagesBadge';
import ImagesWorkArea from './ImagesWorkArea';

const IMAGES_TABS = [
	{ id: 'all', label: 'All', filter: () => true },
	{ id: 'no-image', label: 'No image', filter: ( p ) => ! p.featured_media },
	{
		id: 'has-image',
		label: 'Has image',
		filter: ( p ) => !! p.featured_media,
	},
];

const IMAGES_COLUMNS = [
	{
		label: 'Featured Image',
		width: 180,
		render: ( post ) => <ImagesBadge post={ post } />,
	},
];

/**
 * Root page component for the AI Images admin screen.
 *
 * Available to every tier — credit exhaustion is surfaced inline by
 * ImagesWorkArea via OutOfCreditsNotice when a generation request fails,
 * not by gating the whole screen up front.
 *
 * @return {ReactElement}
 */
export default function ImagesApp() {
	return (
		<div className="plume-page">
			<div className="plume-page-header">
				<h1>Images</h1>
				<p>
					Generate beautiful featured images from a text prompt and
					set them directly on any post or page.
				</p>
			</div>
			<PostListTable
				tabs={ IMAGES_TABS }
				WorkArea={ ImagesWorkArea }
				columns={ IMAGES_COLUMNS }
			/>
		</div>
	);
}
