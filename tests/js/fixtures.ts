/**
 * Internal dependencies
 */
import type {
	Diff,
	Group,
	SourceItem,
	Summary,
	Translation,
} from '../../src/shared/types';
import type { DashboardConfig } from '../../src/dashboard/config';

export const config: DashboardConfig = {
	ready: true,
	namespace: 'tdrift/v1',
	settingsUrl:
		'/wp-admin/options-general.php?page=translation-drift-settings',
	languages: [
		{ code: 'en', name: 'English' },
		{ code: 'fr', name: 'Français' },
		{ code: 'es', name: 'Español' },
	],
	sourceLanguage: 'en',
	postTypes: [
		{ slug: 'post', label: 'Posts' },
		{ slug: 'page', label: 'Pages' },
	],
	authors: [ { id: 1, name: 'Admin' } ],
	translators: [ { id: 7, name: 'Translator FR', langs: [ 'fr' ] } ],
};

export function translation(
	overrides: Partial< Translation > = {}
): Translation {
	return {
		lang: 'fr',
		language: 'Français',
		status: 'outdated',
		status_label: 'Outdated',
		translation_id: 11,
		changed_fields: [ { key: 'title', label: 'Title' } ],
		synced_at: '2026-09-01T10:00:00Z',
		edit_url: '/wp-admin/post.php?post=11&action=edit',
		create_url: null,
		can_mark: true,
		...overrides,
	};
}

export function sourceItem(
	overrides: Partial< SourceItem > = {}
): SourceItem {
	return {
		id: 10,
		title: 'Hello world',
		post_type: 'post',
		post_type_label: 'Post',
		post_status: 'publish',
		source_lang: 'en',
		author: { id: 1, name: 'Admin' },
		modified: '2026-09-20T08:00:00Z',
		edit_url: '/wp-admin/post.php?post=10&action=edit',
		translations: {
			fr: translation(),
			es: translation( {
				lang: 'es',
				language: 'Español',
				status: 'missing',
				status_label: 'Missing',
				translation_id: 0,
				changed_fields: [],
				synced_at: null,
				edit_url: null,
				create_url:
					'/wp-admin/admin-post.php?action=tdrift_translation&source=10&lang=es',
				can_mark: false,
			} ),
		},
		...overrides,
	};
}

export const summary: Summary = {
	totals: { in_sync: 3, outdated: 1, missing: 1, untracked: 0 },
	languages: [
		{ code: 'en', name: 'English', counts: {} },
		{ code: 'fr', name: 'Français', counts: { in_sync: 2, outdated: 1 } },
		{ code: 'es', name: 'Español', counts: { in_sync: 1, missing: 1 } },
	],
	post_types: [
		{
			slug: 'post',
			label: 'Posts',
			counts: { in_sync: 3, outdated: 1, missing: 1 },
		},
	],
	baseline: {
		status: 'done',
		processed: 5,
		updated_at: '2026-09-01 10:00:00',
	},
};

export const diff: Diff = {
	...translation(),
	source_id: 10,
	synced_by: 'Admin',
	fields: [
		{
			key: 'title',
			label: 'Title',
			available: true,
			truncated: false,
			diff: '<table class="diff"><tbody><tr><td class="diff-deletedline"><span class="screen-reader-text">Deleted: </span><del>Old</del> title</td><td class="diff-addedline"><span class="screen-reader-text">Added: </span><ins>New</ins> title</td></tr></tbody></table>',
		},
		{
			key: 'meta:subtitle',
			label: 'Custom field “subtitle”',
			available: false,
			truncated: false,
			diff: '',
		},
	],
	source: {
		id: 10,
		title: 'Hello world',
		edit_url: '/wp-admin/post.php?post=10&action=edit',
		view_url: '/hello-world/',
	},
	translation: {
		id: 11,
		title: 'Bonjour',
		edit_url: '/wp-admin/post.php?post=11&action=edit',
		view_url: '/fr/bonjour/',
	},
};

export function group( overrides: Partial< Group > = {} ): Group {
	return {
		post_id: 11,
		tracked: true,
		role: 'translation',
		lang: 'fr',
		language: 'Français',
		source: {
			id: 10,
			title: 'Hello world',
			lang: 'en',
			language: 'English',
			edit_url: '/wp-admin/post.php?post=10&action=edit',
		},
		translation: translation(),
		translations: {},
		can_mark: true,
		...overrides,
	};
}

/**
 * A fetch Response-like object for apiFetch calls made with `parse: false`.
 *
 * @param body  JSON body.
 * @param total X-WP-Total.
 * @param pages X-WP-TotalPages.
 * @return Response stand-in.
 */
export function pagedResponse( body: unknown, total: number, pages = 1 ) {
	return {
		json: () => Promise.resolve( body ),
		headers: {
			get: ( name: string ) =>
				( {
					'X-WP-Total': String( total ),
					'X-WP-TotalPages': String( pages ),
				} )[ name ] ?? null,
		},
	};
}

export interface FetchOptions {
	path: string;
	method?: string;
	data?: Record< string, unknown >;
	parse?: boolean;
}
