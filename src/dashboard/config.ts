/**
 * Settings printed by PHP before the dashboard script (`window.tdriftDashboard`).
 */
export interface DashboardConfig {
	ready: boolean;
	namespace: string;
	settingsUrl: string;
	languages: { code: string; name: string }[];
	/** Language most sources are written in; its column starts hidden. */
	sourceLanguage: string;
	postTypes: { slug: string; label: string }[];
	authors: { id: number; name: string }[];
	translators: { id: number; name: string; langs: string[] }[];
}

declare global {
	interface Window {
		tdriftDashboard?: Partial< DashboardConfig >;
	}
}

/**
 * Reads the dashboard settings, with safe defaults for anything missing.
 *
 * @return Settings.
 */
export function getConfig(): DashboardConfig {
	const config = window.tdriftDashboard ?? {};

	return {
		ready: config.ready ?? false,
		namespace: config.namespace ?? 'tdrift/v1',
		settingsUrl: config.settingsUrl ?? '',
		languages: config.languages ?? [],
		sourceLanguage: config.sourceLanguage ?? '',
		postTypes: config.postTypes ?? [],
		authors: config.authors ?? [],
		translators: config.translators ?? [],
	};
}
