/**
 * Internal dependencies
 */
import type { DriftStatus } from './status';

/**
 * A tracked field with its label.
 */
export interface FieldLabel {
	key: string;
	label: string;
}

/**
 * Status of one translation, as the REST API returns it.
 */
export interface Translation {
	lang: string;
	language: string;
	status: DriftStatus;
	status_label: string;
	translation_id: number;
	changed_fields: FieldLabel[];
	synced_at: string | null;
	edit_url: string | null;
	create_url: string | null;
	can_mark: boolean;
}

/**
 * A source with the status of each translation (`GET stalelingo/v1/status`).
 */
export interface SourceItem {
	id: number;
	title: string;
	post_type: string;
	post_type_label: string;
	post_status: string;
	source_lang: string;
	author: { id: number; name: string };
	modified: string | null;
	edit_url: string | null;
	translations: Record< string, Translation >;
}

export type StatusCounts = Partial< Record< DriftStatus, number > >;

/**
 * `GET stalelingo/v1/status/summary`.
 */
export interface Summary {
	totals: StatusCounts;
	languages: { code: string; name: string; counts: StatusCounts }[];
	post_types: { slug: string; label: string; counts: StatusCounts }[];
	baseline: BaselineState;
}

export interface BaselineState {
	status: 'none' | 'queued' | 'running' | 'done';
	processed: number;
	updated_at: string;
}

/**
 * Title and links of a post in a diff.
 */
export interface PostLinks {
	id: number;
	title: string;
	edit_url: string | null;
	view_url: string | null;
}

/**
 * One changed field in a diff.
 */
export interface FieldDiff {
	key: string;
	label: string;
	available: boolean;
	truncated: boolean;
	/** Sanitized table HTML from wp_text_diff(); empty when only formatting changed. */
	diff: string;
}

/**
 * `GET stalelingo/v1/diff/{id}`.
 */
export interface Diff extends Translation {
	source_id: number;
	synced_by: string;
	fields: FieldDiff[];
	source: PostLinks;
	translation: PostLinks;
}

/**
 * `GET stalelingo/v1/group/{id}`.
 */
export interface Group {
	post_id: number;
	tracked: boolean;
	role: 'source' | 'translation' | 'none';
	lang: string;
	language: string;
	source: {
		id: number;
		title: string;
		lang: string;
		language: string;
		edit_url: string | null;
	} | null;
	translation: Translation | null;
	translations: Record< string, Translation >;
	can_mark: boolean;
}

/**
 * `POST stalelingo/v1/mark-synced`.
 */
export interface MarkSyncedResult {
	updated: number[];
	failed: { id: number; code: string; message: string }[];
	translations: Record< string, Translation >;
}
