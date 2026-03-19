const LOCALE = 'en-GB';

function parseExifDateTime(value) {
	if (typeof value !== 'string') {
		return null;
	}

	// Accept both `YYYY:MM:DD HH:MM:SS` (EXIF) and `YYYY-MM-DD HH:MM:SS` (API)
	const match = value.match(/^(\d{4})[:\-](\d{2})[:\-](\d{2})\s+(\d{2}):(\d{2}):(\d{2})$/);
	if (!match) {
		return null;
	}

	const iso = `${match[1]}-${match[2]}-${match[3]}T${match[4]}:${match[5]}:${match[6]}`;
	const ts = Date.parse(iso);
	return Number.isNaN(ts) ? null : ts;
}

function parseIptcDateTime(dateValue, timeValue) {
	if (typeof dateValue !== 'string') {
		return null;
	}

	// If the API provided a combined timestamp (YYYY-MM-DD HH:MM:SS), parse it directly
	if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/.test(dateValue)) {
		const ts = Date.parse(dateValue.replace(' ', 'T'));
		return Number.isNaN(ts) ? null : ts;
	}

	// Fallback: original IPTC format YYYYMMDD + optional HHMM or HHMMSS
	const dateMatch = dateValue.match(/^(\d{4})(\d{2})(\d{2})$/);
	if (!dateMatch) {
		return null;
	}

	let timePart = '00:00:00';
	if (typeof timeValue === 'string') {
		// timeValue might itself be a combined timestamp (if server wrote formatted value)
		if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/.test(timeValue)) {
			const ts = Date.parse(timeValue.replace(' ', 'T'));
			return Number.isNaN(ts) ? null : ts;
		}

		const t = timeValue.slice(0, 6);
		const timeMatch = t.match(/^(\d{2})(\d{2})(\d{2})$/);
		if (timeMatch) {
			timePart = `${timeMatch[1]}:${timeMatch[2]}:${timeMatch[3]}`;
		}
	}

	const iso = `${dateMatch[1]}-${dateMatch[2]}-${dateMatch[3]}T${timePart}`;
	const ts = Date.parse(iso);
	return Number.isNaN(ts) ? null : ts;
}

function getCaptureTimestamp(item) {
	const exifCandidates = [item.datetime_original, item.datetime_digitized, item.datetime];

	for (const raw of exifCandidates) {
		const ts = parseExifDateTime(raw);
		if (ts !== null) {
			return ts;
		}
	}

	return parseIptcDateTime(item.date_created, item.time_created);
}

function formatTimestamp(ts) {
	if (ts === null) {
		return 'Unknown capture date';
	}

	return new Date(ts).toLocaleDateString(LOCALE, {
		month: 'long',
		year: 'numeric',
	});
}

function getImageTitle(item) {
	return typeof item.title === 'string' && item.title.trim() !== '' ? item.title : 'Untitled';
}

function getImageLocation(item) {
	const country = item.country;
	const includeCountry = typeof country === 'string' && country.trim().toLowerCase() !== 'united kingdom';

	const parts = [item.sublocation, item.city, item.state_province, includeCountry ? country : null].filter(Boolean);

	const seen = new Set();
	const uniqueParts = [];

	for (const part of parts) {
		const key = part.toLowerCase();
		if (seen.has(key)) {
			continue;
		}

		seen.add(key);
		uniqueParts.push(part);
	}

	if (uniqueParts.length === 0) {
		return 'Unknown location';
	}

	return uniqueParts.join(', ');
}

function getImageTags(item) {
	if (!Array.isArray(item.keywords)) {
		return '';
	}

	return item.keywords.join(', ');
}

function getImageDimensions(item) {
	const width = Number(item.width);
	const height = Number(item.height);

	if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
		return {
			width: 3,
			height: 2,
		};
	}

	return {
		width,
		height,
	};
}

function getGridMetrics(item) {
	const dimensions = getImageDimensions(item);
	const width = dimensions.width;
	const height = dimensions.height;
	const ratio = width / height;

	return {
		ratio,
		flexGrow: ratio * 100,
		paddingBottom: (height / width) * 100,
	};
}

function getTargetHeight() {
	if (typeof window !== 'undefined' && window.innerWidth > 1920) {
		return 420;
	}

	return 320;
}

function applyTargetHeightCssVariable() {
	if (typeof document === 'undefined') {
		return;
	}

	document.body.style.setProperty('--grid-target-height', `${getTargetHeight()}px`);
}

function initResponsiveTargetHeight() {
	applyTargetHeightCssVariable();

	if (typeof window !== 'undefined') {
		window.addEventListener('resize', applyTargetHeightCssVariable);
	}
}

const SHOW_CAPTIONS_STORAGE_KEY = 'gallery.showCaptions';
const PER_PAGE_STORAGE_KEY = 'gallery.perPage';
const PER_PAGE_OPTIONS = [20, 40, 60, 100];
const DEFAULT_PER_PAGE = 20;
const DEFAULT_PAGE_TITLE = document.title;
let selectedMonthYear = '';
let selectedCountry = '';
let selectedPerPage = DEFAULT_PER_PAGE;
let currentPage = 1;
let totalPages = 0;
let totalImages = 0;
let isLoadingPage = false;
let filterMetaLoaded = false;
let closeSettingsDrawer = () => {};
let isSettingsDrawerOpen = () => false;

function setShowCaptionsEnabled(enabled) {
	document.body.classList.toggle('show-captions', enabled);
}

function createCaptionSettingsPanel() {
	const status = document.getElementById('status');
	if (!status || !status.parentNode) {
		return null;
	}

	const imageList = document.getElementById('image-list');
	if (!(imageList instanceof HTMLElement) || !imageList.parentNode) {
		return null;
	}

	const settingsToggle = document.getElementById('settings-toggle');

	const panel = document.createElement('div');
	panel.id = 'settings-panel';
	panel.setAttribute('aria-label', 'Gallery settings');

	const drawer = document.createElement('aside');
	drawer.id = 'settings-drawer';
	drawer.setAttribute('aria-label', 'Gallery settings drawer');

	const label = document.createElement('label');
	label.setAttribute('for', 'show-captions-checkbox');

	const checkbox = document.createElement('input');
	checkbox.type = 'checkbox';
	checkbox.id = 'show-captions-checkbox';

	label.appendChild(checkbox);
	label.appendChild(document.createTextNode(' Keep full image captions visible'));

	const monthYearLabel = document.createElement('label');
	monthYearLabel.setAttribute('for', 'month-year-filter-select');
	monthYearLabel.textContent = 'Month / year';

	const monthYearSelect = document.createElement('select');
	monthYearSelect.id = 'month-year-filter-select';

	const allOption = document.createElement('option');
	allOption.value = '';
	allOption.textContent = 'All months';
	monthYearSelect.appendChild(allOption);

	const countryLabel = document.createElement('label');
	countryLabel.setAttribute('for', 'country-filter-select');
	countryLabel.textContent = 'Country / region';

	const countrySelect = document.createElement('select');
	countrySelect.id = 'country-filter-select';

	const allCountriesOption = document.createElement('option');
	allCountriesOption.value = '';
	allCountriesOption.textContent = 'All countries and regions';
	countrySelect.appendChild(allCountriesOption);

	const perPageLabel = document.createElement('label');
	perPageLabel.setAttribute('for', 'per-page-select');
	perPageLabel.textContent = 'Per page';

	const perPageSelect = document.createElement('select');
	perPageSelect.id = 'per-page-select';

	for (const perPageOption of PER_PAGE_OPTIONS) {
		const option = document.createElement('option');
		option.value = String(perPageOption);
		option.textContent = String(perPageOption);
		perPageSelect.appendChild(option);
	}

	const resetButton = document.createElement('button');
	resetButton.type = 'button';
	resetButton.id = 'filters-reset-button';
	resetButton.textContent = 'Reset filters';

	panel.appendChild(label);
	panel.appendChild(monthYearLabel);
	panel.appendChild(monthYearSelect);
	panel.appendChild(countryLabel);
	panel.appendChild(countrySelect);
	panel.appendChild(perPageLabel);
	panel.appendChild(perPageSelect);
	panel.appendChild(resetButton);
	drawer.appendChild(panel);

	const backdrop = document.createElement('div');
	backdrop.id = 'settings-backdrop';
	backdrop.hidden = true;

	const swipeHint = document.createElement('div');
	swipeHint.id = 'settings-swipe-hint';
	swipeHint.textContent = 'Swipe to close';
	drawer.appendChild(swipeHint);

	let swipeHintTimerId = null;

	let touchStartX = 0;
	let touchStartY = 0;
	let touchDeltaX = 0;
	let isTrackingTouch = false;
	let isSwipingDrawer = false;

	status.parentNode.insertBefore(drawer, status);
	document.body.appendChild(backdrop);

	const isMobileDrawerMode = () => {
		return typeof window !== 'undefined' && window.matchMedia('(max-width: 48rem)').matches;
	};

	const resetDrawerDragStyles = () => {
		drawer.style.transition = '';
		drawer.style.transform = '';
	};

	const clearSwipeHintTimer = () => {
		if (swipeHintTimerId !== null) {
			window.clearTimeout(swipeHintTimerId);
			swipeHintTimerId = null;
		}
	};

	const setSwipeHintVisible = (isVisible) => {
		swipeHint.classList.toggle('is-visible', isVisible);
	};

	const getActiveFilterCount = () => {
		let count = 0;
		if (monthYearSelect.value !== '') {
			count += 1;
		}

		if (countrySelect.value !== '') {
			count += 1;
		}

		if (normalizePerPage(perPageSelect.value) !== DEFAULT_PER_PAGE) {
			count += 1;
		}

		return count;
	};

	const updateSettingsToggleLabel = (isOpen) => {
		if (!(settingsToggle instanceof HTMLButtonElement)) {
			return;
		}

		const activeFilterCount = getActiveFilterCount();
		const countSuffix = activeFilterCount > 0 ? ` (${activeFilterCount})` : '';
		settingsToggle.textContent = isOpen ? `Close settings${countSuffix}` : `Open settings${countSuffix}`;
	};

	const setSettingsOpen = (isOpen) => {
		document.body.classList.toggle('settings-open', isOpen);
		backdrop.hidden = !isOpen;
		resetDrawerDragStyles();
		clearSwipeHintTimer();

		if (!isOpen) {
			setSwipeHintVisible(false);
		} else if (isMobileDrawerMode()) {
			setSwipeHintVisible(false);
			swipeHintTimerId = window.setTimeout(() => {
				setSwipeHintVisible(true);
				swipeHintTimerId = null;
			}, 1000);
		}

		if (settingsToggle instanceof HTMLButtonElement) {
			settingsToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
		}

		updateSettingsToggleLabel(isOpen);
	};

	const closeSettings = () => setSettingsOpen(false);
	const openSettings = () => setSettingsOpen(true);

	if (settingsToggle instanceof HTMLButtonElement) {
		settingsToggle.addEventListener('click', () => {
			if (document.body.classList.contains('settings-open')) {
				closeSettings();
			} else {
				openSettings();
			}
		});
	}

	backdrop.addEventListener('click', closeSettings);

	drawer.addEventListener(
		'touchstart',
		(event) => {
			if (!document.body.classList.contains('settings-open') || !isMobileDrawerMode()) {
				return;
			}

			if (event.touches.length !== 1) {
				return;
			}

			const touch = event.touches[0];
			touchStartX = touch.clientX;
			touchStartY = touch.clientY;
			touchDeltaX = 0;
			isTrackingTouch = true;
			isSwipingDrawer = false;
		},
		{ passive: true },
	);

	drawer.addEventListener(
		'touchmove',
		(event) => {
			if (!isTrackingTouch || event.touches.length !== 1) {
				return;
			}

			const touch = event.touches[0];
			const deltaX = touch.clientX - touchStartX;
			const deltaY = touch.clientY - touchStartY;

			if (!isSwipingDrawer) {
				if (Math.abs(deltaX) < 10 || Math.abs(deltaX) <= Math.abs(deltaY)) {
					return;
				}

				isSwipingDrawer = true;
			}

			if (deltaX <= 0) {
				touchDeltaX = 0;
				return;
			}

			if (event.cancelable) {
				event.preventDefault();
			}

			touchDeltaX = deltaX;
			drawer.style.transition = 'none';
			drawer.style.transform = `translateX(${touchDeltaX}px)`;
		},
		{ passive: false },
	);

	const handleTouchEnd = () => {
		if (!isTrackingTouch) {
			return;
		}

		const threshold = Math.max(72, drawer.clientWidth * 0.22);
		const shouldClose = isSwipingDrawer && touchDeltaX > threshold;

		isTrackingTouch = false;
		isSwipingDrawer = false;
		touchDeltaX = 0;

		if (shouldClose) {
			closeSettings();
			return;
		}

		resetDrawerDragStyles();
	};

	drawer.addEventListener('touchend', handleTouchEnd, { passive: true });
	drawer.addEventListener('touchcancel', handleTouchEnd, { passive: true });

	if (typeof window !== 'undefined') {
		window.addEventListener('resize', () => {
			resetDrawerDragStyles();
			if (!isMobileDrawerMode()) {
				clearSwipeHintTimer();
				setSwipeHintVisible(false);
			}
		});
	}
	updateSettingsToggleLabel(false);

	const loadMoreContainer = document.createElement('div');
	loadMoreContainer.id = 'load-more-container';
	loadMoreContainer.hidden = true;

	const loadMoreButton = document.createElement('button');
	loadMoreButton.type = 'button';
	loadMoreButton.id = 'load-more-button';
	loadMoreButton.textContent = 'Load more';

	loadMoreContainer.appendChild(loadMoreButton);
	imageList.parentNode.insertBefore(loadMoreContainer, imageList.nextSibling);

	return {
		checkbox,
		monthYearSelect,
		countrySelect,
		perPageSelect,
		resetButton,
		loadMoreButton,
		openSettings,
		closeSettings,
		isSettingsOpen: () => document.body.classList.contains('settings-open'),
		refreshSettingsToggleLabel: () => updateSettingsToggleLabel(document.body.classList.contains('settings-open')),
	};
}

function normalizePerPage(value) {
	const parsed = Number.parseInt(String(value), 10);
	return PER_PAGE_OPTIONS.includes(parsed) ? parsed : DEFAULT_PER_PAGE;
}

function initSettingsPanel() {
	const settings = createCaptionSettingsPanel();
	if (!settings) {
		return;
	}

	const checkbox = settings.checkbox;
	const monthYearSelect = settings.monthYearSelect;
	const countrySelect = settings.countrySelect;
	const perPageSelect = settings.perPageSelect;
	const resetButton = settings.resetButton;
	const loadMoreButton = settings.loadMoreButton;
	const closeSettings = settings.closeSettings;
	const isSettingsOpen = settings.isSettingsOpen;
	const refreshSettingsToggleLabel = settings.refreshSettingsToggleLabel;
	let updateResetButtonVisibility = () => {};
	if (!(checkbox instanceof HTMLInputElement)) {
		return;
	}

	closeSettingsDrawer = closeSettings;
	isSettingsDrawerOpen = isSettingsOpen;

	let enabled = false;
	try {
		enabled = localStorage.getItem(SHOW_CAPTIONS_STORAGE_KEY) === '1';
	} catch (_error) {
		enabled = false;
	}

	checkbox.checked = enabled;
	setShowCaptionsEnabled(enabled);

	try {
		selectedPerPage = normalizePerPage(localStorage.getItem(PER_PAGE_STORAGE_KEY));
	} catch (_error) {
		selectedPerPage = DEFAULT_PER_PAGE;
	}

	if (perPageSelect instanceof HTMLSelectElement) {
		perPageSelect.value = String(selectedPerPage);
	}

	checkbox.addEventListener('change', () => {
		const isEnabled = checkbox.checked;
		setShowCaptionsEnabled(isEnabled);

		try {
			localStorage.setItem(SHOW_CAPTIONS_STORAGE_KEY, isEnabled ? '1' : '0');
		} catch (_error) {}
	});

	if (monthYearSelect instanceof HTMLSelectElement) {
		monthYearSelect.addEventListener('change', () => {
			selectedMonthYear = monthYearSelect.value;
			updateResetButtonVisibility();
			refreshSettingsToggleLabel();
			closeSettings();
			run();
		});
	}

	if (countrySelect instanceof HTMLSelectElement) {
		countrySelect.addEventListener('change', () => {
			selectedCountry = countrySelect.value;
			updateResetButtonVisibility();
			refreshSettingsToggleLabel();
			closeSettings();
			run();
		});
	}

	if (perPageSelect instanceof HTMLSelectElement) {
		perPageSelect.addEventListener('change', () => {
			selectedPerPage = normalizePerPage(perPageSelect.value);

			try {
				localStorage.setItem(PER_PAGE_STORAGE_KEY, String(selectedPerPage));
			} catch (_error) {}

			updateResetButtonVisibility();
			refreshSettingsToggleLabel();
			closeSettings();
			run();
		});
	}

	if (loadMoreButton instanceof HTMLButtonElement) {
		loadMoreButton.addEventListener('click', () => {
			loadMore();
		});
	}

	if (
		resetButton instanceof HTMLButtonElement &&
		monthYearSelect instanceof HTMLSelectElement &&
		countrySelect instanceof HTMLSelectElement &&
		perPageSelect instanceof HTMLSelectElement
	) {
		updateResetButtonVisibility = () => {
			const hasActiveFilter =
				monthYearSelect.value !== '' || countrySelect.value !== '' || normalizePerPage(perPageSelect.value) !== DEFAULT_PER_PAGE;
			resetButton.hidden = !hasActiveFilter;
			refreshSettingsToggleLabel();
		};

		updateResetButtonVisibility();

		monthYearSelect.addEventListener('change', updateResetButtonVisibility);
		countrySelect.addEventListener('change', updateResetButtonVisibility);
		perPageSelect.addEventListener('change', updateResetButtonVisibility);

		resetButton.addEventListener('click', () => {
			selectedMonthYear = '';
			selectedCountry = '';
			selectedPerPage = DEFAULT_PER_PAGE;
			monthYearSelect.value = '';
			countrySelect.value = '';
			perPageSelect.value = String(DEFAULT_PER_PAGE);

			try {
				localStorage.setItem(PER_PAGE_STORAGE_KEY, String(DEFAULT_PER_PAGE));
			} catch (_error) {}

			updateResetButtonVisibility();
			closeSettings();
			run();
		});
	}
}

function getLocationTerms(item) {
	const country = item.country;
	const terms = [];

	const isUnitedKingdom = typeof country === 'string' && country.trim().toLowerCase() === 'united kingdom';

	if (typeof country === 'string' && country.trim() !== '' && !isUnitedKingdom) {
		terms.push(country.trim());
	}

	if (isUnitedKingdom) {
		const regionalTerms = [item.state_province, item.sublocation].filter((term) => typeof term === 'string' && term.trim() !== '');

		for (const term of regionalTerms) {
			terms.push(term.trim());
		}
	}

	if (terms.length === 0) {
		return ['Unknown location'];
	}

	return Array.from(new Set(terms));
}

function getMonthYearKeyFromTimestamp(ts) {
	if (ts === null) {
		return null;
	}

	const date = new Date(ts);
	if (Number.isNaN(date.getTime())) {
		return null;
	}

	const year = String(date.getFullYear());
	const month = String(date.getMonth() + 1).padStart(2, '0');
	return `${year}-${month}`;
}

function formatMonthYearKey(monthYearKey) {
	if (typeof monthYearKey !== 'string') {
		return monthYearKey;
	}

	const match = monthYearKey.match(/^(\d{4})-(\d{2})$/);
	if (!match) {
		return monthYearKey;
	}

	const year = Number(match[1]);
	const monthIndex = Number(match[2]) - 1;
	if (!Number.isFinite(year) || monthIndex < 0 || monthIndex > 11) {
		return monthYearKey;
	}

	const date = new Date(year, monthIndex, 1);
	return date.toLocaleDateString(LOCALE, {
		month: 'long',
		year: 'numeric',
	});
}

function populateMonthYearFilterOptions(monthYears) {
	const monthYearSelect = document.getElementById('month-year-filter-select');
	if (!(monthYearSelect instanceof HTMLSelectElement)) {
		return;
	}

	const values = Array.isArray(monthYears) ? monthYears.filter((value) => typeof value === 'string' && value !== '') : [];
	const sortedMonthYears = Array.from(new Set(values)).sort((a, b) => b.localeCompare(a, LOCALE));

	const existingValue = monthYearSelect.value;
	while (monthYearSelect.options.length > 1) {
		monthYearSelect.remove(1);
	}

	for (const monthYear of sortedMonthYears) {
		const option = document.createElement('option');
		option.value = monthYear;
		option.textContent = formatMonthYearKey(monthYear);
		monthYearSelect.appendChild(option);
	}

	monthYearSelect.value = existingValue;
}

function populateCountryFilterOptions(countries) {
	const countrySelect = document.getElementById('country-filter-select');
	if (!(countrySelect instanceof HTMLSelectElement)) {
		return;
	}

	const values = Array.isArray(countries) ? countries.filter((value) => typeof value === 'string' && value !== '') : [];
	const sortedCountries = Array.from(new Set(values)).sort((a, b) => a.localeCompare(b, LOCALE));

	const existingValue = countrySelect.value;
	while (countrySelect.options.length > 1) {
		countrySelect.remove(1);
	}

	for (const country of sortedCountries) {
		const option = document.createElement('option');
		option.value = country;
		option.textContent = country;
		countrySelect.appendChild(option);
	}

	countrySelect.value = existingValue;
}

async function fetchMetaData() {
	const requestUrl = `${window.location.origin}/api/meta`;
	const response = await fetch(requestUrl, {
		method: 'GET',
	});

	if (!response.ok) {
		throw new Error(`Metadata API returned ${response.status}`);
	}

	const data = await response.json();
	if (!data || typeof data !== 'object') {
		throw new Error('Unexpected metadata API response format.');
	}

	const monthYears = Array.isArray(data.month_years) ? data.month_years : [];

	return {
		monthYears,
	};
}

function getCountryFilterTerm(item) {
	if (!item || typeof item !== 'object') {
		return null;
	}

	const country = typeof item.country === 'string' ? item.country.trim() : '';
	const stateProvince = typeof item.state_province === 'string' ? item.state_province.trim() : '';

	if (country === '') {
		return null;
	}

	if (country.toLowerCase() === 'united kingdom') {
		return stateProvince !== '' ? stateProvince : country;
	}

	return country;
}

async function fetchCountryFilterOptions() {
	const terms = new Set();
	let page = 1;
	let totalPages = 1;

	while (page <= totalPages) {
		const response = await fetchImageData('', '', page, 100);
		totalPages = Math.max(response.totalPages, 1);

		for (const item of response.data) {
			const term = getCountryFilterTerm(item);
			if (term) {
				terms.add(term);
			}
		}

		if (response.totalPages === 0) {
			break;
		}

		page += 1;
	}

	return Array.from(terms).sort((a, b) => a.localeCompare(b, LOCALE));
}

function readPositiveIntHeader(headers, name, fallback = 0) {
	const raw = headers.get(name);
	if (typeof raw !== 'string') {
		return fallback;
	}

	const parsed = Number.parseInt(raw, 10);
	return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
}

async function fetchImageData(monthYear = '', country = '', page = 1, perPage = 20) {
	let requestUrl;
	const origin = window.location.origin;

	// Use readable path when filters are provided: /api/filter/location/<value>/month_year/<yyyy-mm>/
	if (monthYear === '' && country === '') {
		requestUrl = `${origin}/api`;
	} else {
		const parts = ['api', 'filter'];

		if (country !== '') {
			// Use application/x-www-form-urlencoded style for spaces (+)
			const encodedCountry = encodeURIComponent(String(country).toLowerCase()).replace(/%20/g, '+');
			parts.push('location', encodedCountry);
		}

		if (monthYear !== '') {
			// monthYear is numeric-like (YYYY-MM) but normalize and percent-encode anyway
			const encodedMonthYear = encodeURIComponent(String(monthYear).toLowerCase()).replace(/%20/g, '+');
			parts.push('month_year', encodedMonthYear);
		}

		requestUrl = `${origin}/${parts.join('/')}/`;
	}

	const request = new URL(requestUrl);
	request.searchParams.set('page', String(page));
	request.searchParams.set('per_page', String(perPage));

	const response = await fetch(request.toString(), {
		method: 'GET',
	});

	if (!response.ok) {
		throw new Error(`API returned ${response.status}`);
	}

	const data = await response.json();
	if (!Array.isArray(data)) {
		throw new Error('Unexpected API response format.');
	}

	const responsePage = readPositiveIntHeader(response.headers, 'x-page', page);
	const responseTotalPages = readPositiveIntHeader(response.headers, 'x-total-pages', 0);
	const responseTotal = readPositiveIntHeader(response.headers, 'x-total', data.length);

	return {
		data,
		page: responsePage,
		totalPages: responseTotalPages,
		total: responseTotal,
	};
}

function renderImages(images, append = false) {
	const list = document.getElementById('image-list');
	if (!(list instanceof HTMLElement)) {
		return;
	}

	if (!append) {
		list.innerHTML = '';
	}

	for (const item of images) {
		const li = document.createElement('li');
		li.className = 'image-item c-grid500__item';

		const metrics = getGridMetrics(item);
		li.style.flexGrow = String(metrics.flexGrow);
		li.style.setProperty('--item-ratio', String(metrics.ratio));
		li.style.flexBasis = 'calc(var(--grid-target-height, 320px) * var(--item-ratio))';

		const card = document.createElement('a');
		card.className = 'c-grid500__itemlink';

		const uncollapse = document.createElement('i');
		uncollapse.className = 'c-grid500__uncollapse';
		uncollapse.style.paddingBottom = `${metrics.paddingBottom}%`;

		const figure = document.createElement('figure');
		figure.className = 'c-grid500__figure';

		const img = document.createElement('img');
		img.className = 'c-grid500__image';
		img.src = item.url;
		img.alt = getImageTitle(item);
		img.loading = 'lazy';

		const meta = document.createElement('div');
		meta.className = 'meta';

		const date = document.createElement('div');
		date.className = 'date';
		date.textContent = formatTimestamp(item.captureTs);

		const title = document.createElement('div');
		title.className = 'title';
		title.textContent = getImageTitle(item);

		const location = document.createElement('div');
		location.className = 'location';
		location.textContent = getImageLocation(item);

		const tags = document.createElement('div');
		tags.className = 'tags';
		tags.textContent = getImageTags(item);

		meta.appendChild(title);
		meta.appendChild(location);
		meta.appendChild(tags);
		meta.appendChild(date);

		const metaSecondary = document.createElement('div');
		metaSecondary.className = 'meta-secondary';

		const secondaryDate = document.createElement('div');
		secondaryDate.className = 'date';
		secondaryDate.textContent = formatTimestamp(item.captureTs);

		const secondaryLocation = document.createElement('div');
		secondaryLocation.className = 'location';
		secondaryLocation.textContent = getImageLocation(item);

		metaSecondary.appendChild(secondaryDate);
		metaSecondary.appendChild(secondaryLocation);

		figure.appendChild(img);
		card.appendChild(uncollapse);
		card.appendChild(figure);
		card.appendChild(meta);
		card.appendChild(metaSecondary);
		li.appendChild(card);

		// Preserve normal link behavior for modified clicks while using SPA navigation for plain clicks.
		const photoId = getPhotoIdFromUrl(item.url);
		if (photoId) {
			card.href = `/photo/${photoId}/`;
			card.addEventListener('click', (event) => {
				if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
					return;
				}

				event.preventDefault();
				navigateToPhoto(photoId);
			});
		}

		list.appendChild(li);
	}
}

function updateLoadMoreButton() {
	const container = document.getElementById('load-more-container');
	const button = document.getElementById('load-more-button');
	if (!(container instanceof HTMLElement) || !(button instanceof HTMLButtonElement)) {
		return;
	}

	const hasMorePages = currentPage < totalPages;
	const remaining = Math.max(totalImages - allImagesCache.length, 0);
	const nextLoadCount = Math.min(selectedPerPage, remaining);
	container.hidden = !hasMorePages;
	button.hidden = !hasMorePages;
	button.disabled = isLoadingPage || !hasMorePages;

	if (isLoadingPage) {
		button.textContent = 'Loading…';
		return;
	}

	if (!hasMorePages || nextLoadCount <= 0) {
		button.textContent = 'Load more';
		return;
	}

	const noun = nextLoadCount === 1 ? 'image' : 'images';
	button.textContent = `Load ${nextLoadCount} more ${noun}`;
}

function updateStatusText() {
	const status = document.getElementById('status');
	if (!(status instanceof HTMLElement)) {
		return;
	}

	const imageCount = allImagesCache.length;
	const imageNoun = imageCount === 1 ? 'image' : 'images';
	const totalNoun = totalImages === 1 ? 'image' : 'images';
	const monthYearLabel = selectedMonthYear !== '' ? formatMonthYearKey(selectedMonthYear) : '';

	if (imageCount === 0) {
		if (selectedCountry !== '' && selectedMonthYear !== '') {
			status.textContent = `No images found from ${selectedCountry} in ${monthYearLabel}.`;
		} else if (selectedCountry !== '') {
			status.textContent = `No images found from ${selectedCountry}.`;
		} else if (selectedMonthYear !== '') {
			status.textContent = `No images found in ${monthYearLabel}.`;
		} else {
			status.textContent = 'No images found.';
		}
		return;
	}

	const imageLabel = `Showing ${imageCount} of ${totalImages} ${totalNoun}`;

	if (selectedCountry !== '' && selectedMonthYear !== '') {
		status.textContent = `${imageLabel} from ${selectedCountry} in ${monthYearLabel}.`;
	} else if (selectedCountry !== '') {
		status.textContent = `${imageLabel} from ${selectedCountry}.`;
	} else if (selectedMonthYear !== '') {
		status.textContent = `${imageLabel} in ${monthYearLabel}.`;
	} else {
		status.textContent = `${imageLabel}.`;
	}
}

function toItemsWithCaptureTs(items) {
	return items.map((item) => ({
		...item,
		captureTs: getCaptureTimestamp(item),
	}));
}

async function run() {
	const status = document.getElementById('status');
	if (!(status instanceof HTMLElement)) {
		return;
	}

	try {
		currentPage = 1;
		totalPages = 0;
		totalImages = 0;
		allImagesCache = [];
		renderImages([]);

		if (!filterMetaLoaded) {
			try {
				const meta = await fetchMetaData();
				const countries = await fetchCountryFilterOptions();
				populateMonthYearFilterOptions(meta.monthYears);
				populateCountryFilterOptions(countries);
				filterMetaLoaded = true;
			} catch (metaError) {
				console.warn('Failed to load filter metadata:', metaError);
			}
		}

		isLoadingPage = true;
		updateLoadMoreButton();
		const response = await fetchImageData(selectedMonthYear, selectedCountry, currentPage, selectedPerPage);
		isLoadingPage = false;

		currentPage = Math.max(response.page, 1);
		totalPages = Math.max(response.totalPages, 0);
		totalImages = Math.max(response.total, 0);

		const withCaptureDates = toItemsWithCaptureTs(response.data);
		allImagesCache = withCaptureDates;

		renderImages(withCaptureDates, false);
		updateStatusText();
		updateLoadMoreButton();
	} catch (error) {
		isLoadingPage = false;
		updateLoadMoreButton();
		status.textContent = `Failed to load images: ${error.message}`;
	}
}

async function loadMore() {
	if (isLoadingPage || currentPage >= totalPages) {
		return;
	}

	const status = document.getElementById('status');
	if (!(status instanceof HTMLElement)) {
		return;
	}

	try {
		isLoadingPage = true;
		updateLoadMoreButton();

		const nextPage = currentPage + 1;
		const response = await fetchImageData(selectedMonthYear, selectedCountry, nextPage, selectedPerPage);

		currentPage = Math.max(response.page, nextPage);

		const additionalItems = toItemsWithCaptureTs(response.data);
		allImagesCache = [...allImagesCache, ...additionalItems];
		renderImages(additionalItems, true);

		updateStatusText();
	} catch (error) {
		status.textContent = `Failed to load more images: ${error.message}`;
	} finally {
		isLoadingPage = false;
		updateLoadMoreButton();
	}
}

// SPA functionality
let allImagesCache = [];

function getPhotoIdFromUrl(imageUrl) {
	if (typeof imageUrl !== 'string') {
		return null;
	}

	let pathname = imageUrl;
	try {
		pathname = new URL(imageUrl, window.location.origin).pathname;
	} catch (_error) {
		pathname = imageUrl.split('?')[0].split('#')[0];
	}

	// Extract filename without extension from URL like "/media/20170919-_DSF1840.jpg?v=..."
	const match = pathname.match(/\/([^/]+)\.(jpe?g|png|gif|webp|avif)$/i);
	return match ? match[1] : null;
}

function findImageByPhotoId(photoId) {
	return allImagesCache.find((item) => {
		const itemPhotoId = getPhotoIdFromUrl(item.url);
		return itemPhotoId === photoId;
	});
}

function getPhotoIndexByPhotoId(photoId) {
	return allImagesCache.findIndex((item) => {
		const itemPhotoId = getPhotoIdFromUrl(item.url);
		return itemPhotoId === photoId;
	});
}

function getCurrentRoute() {
	const path = window.location.pathname;
	const photoMatch = path.match(/^\/photo\/([^/]+)\/?$/);

	if (photoMatch) {
		return {
			type: 'photo',
			photoId: photoMatch[1],
		};
	}

	return { type: 'list' };
}

function navigateToPhoto(photoId) {
	const newUrl = `/photo/${photoId}/`;
	window.history.pushState({ type: 'photo', photoId }, '', newUrl);
	showDetailView(photoId);
}

function navigateToList() {
	window.history.pushState({ type: 'list' }, '', '/');
	hideDetailView();
}

function navigateDetailByOffset(offset) {
	const route = getCurrentRoute();
	if (route.type !== 'photo') {
		return;
	}

	const currentIndex = getPhotoIndexByPhotoId(route.photoId);
	if (currentIndex < 0) {
		return;
	}

	const targetIndex = currentIndex + offset;
	if (targetIndex < 0 || targetIndex >= allImagesCache.length) {
		return;
	}

	const targetItem = allImagesCache[targetIndex];
	const targetPhotoId = getPhotoIdFromUrl(targetItem?.url);
	if (targetPhotoId) {
		navigateToPhoto(targetPhotoId);
	}
}

// Meta tag management for detail view
function setMetaTag(property, content, useProperty = true) {
	const attributeName = useProperty ? 'property' : 'name';
	let tag = document.querySelector(`meta[${attributeName}="${property}"]`);

	if (!tag) {
		tag = document.createElement('meta');
		tag.setAttribute(attributeName, property);
		document.head.appendChild(tag);
	}

	tag.setAttribute('content', content);
	tag.dataset.dynamicMeta = 'true';
}

function setCanonicalLink(url) {
	let link = document.querySelector('link[rel="canonical"]');

	if (!link) {
		link = document.createElement('link');
		link.rel = 'canonical';
		document.head.appendChild(link);
	}

	link.href = url;
	link.dataset.dynamicMeta = 'true';
}

function setDetailViewMetaTags(image, photoUrl, imageUrl) {
	const title = getImageTitle(image);
	const location = getImageLocation(image);
	const description = `${title} · ${location}`;

	// Set canonical link
	setCanonicalLink(photoUrl);

	// Set Open Graph tags
	setMetaTag('og:title', title);
	setMetaTag('og:description', description);
	setMetaTag('og:image', imageUrl);
	setMetaTag('og:url', photoUrl);
	setMetaTag('og:type', 'website');
	setMetaTag('og:site_name', DEFAULT_PAGE_TITLE);

	// Set Twitter Card tags
	setMetaTag('twitter:card', 'summary_large_image', false);
	setMetaTag('twitter:title', title, false);
	setMetaTag('twitter:description', description, false);
	setMetaTag('twitter:image', imageUrl, false);
}

function clearDetailViewMetaTags() {
	// Remove all dynamically created meta tags and canonical link
	const dynamicTags = document.querySelectorAll('[data-dynamic-meta="true"]');
	dynamicTags.forEach((tag) => tag.remove());
}

function markServerRenderedMetaTagsAsDynamic() {
	// Mark server-rendered meta tags so they can be managed by JavaScript
	const selectors = [
		'link[rel="canonical"]',
		'meta[property="og:title"]',
		'meta[property="og:description"]',
		'meta[property="og:image"]',
		'meta[property="og:url"]',
		'meta[property="og:type"]',
		'meta[property="og:site_name"]',
		'meta[name="twitter:card"]',
		'meta[name="twitter:title"]',
		'meta[name="twitter:description"]',
		'meta[name="twitter:image"]',
	];

	selectors.forEach((selector) => {
		const element = document.querySelector(selector);
		if (element && !element.dataset.dynamicMeta) {
			element.dataset.dynamicMeta = 'true';
		}
	});
}

function showDetailView(photoId) {
	const image = findImageByPhotoId(photoId);
	const imageIndex = getPhotoIndexByPhotoId(photoId);
	if (!image) {
		console.error('Image not found:', photoId);
		navigateToList();
		return;
	}

	document.title = `${getImageTitle(image)} – ${DEFAULT_PAGE_TITLE}`;

	// Set meta tags for detail view
	const photoUrl = `${window.location.origin}/photo/${photoId}/`;
	const imageUrl = `${window.location.origin}${image.url}`;
	setDetailViewMetaTags(image, photoUrl, imageUrl);

	const detailView = document.getElementById('detail-view');
	if (!detailView) {
		return;
	}

	// Build detail view content
	detailView.innerHTML = '';

	const closeButton = document.createElement('button');
	closeButton.className = 'detail-view__close';
	closeButton.textContent = '× Close';
	closeButton.setAttribute('aria-label', 'Close detail view');
	closeButton.addEventListener('click', () => {
		navigateToList();
	});

	const previousItem = imageIndex > 0 ? allImagesCache[imageIndex - 1] : null;
	const nextItem = imageIndex >= 0 && imageIndex < allImagesCache.length - 1 ? allImagesCache[imageIndex + 1] : null;
	const hasAnyNeighbor = previousItem !== null || nextItem !== null;

	const nav = document.createElement('div');
	nav.className = 'detail-view__nav';
	nav.hidden = !hasAnyNeighbor;

	const previousButton = document.createElement('button');
	previousButton.className = 'detail-view__nav-button detail-view__nav-button--previous';
	previousButton.type = 'button';
	previousButton.textContent = '← Previous';
	previousButton.setAttribute('aria-label', 'Show previous image');

	if (previousItem === null) {
		previousButton.disabled = true;
	} else {
		previousButton.addEventListener('click', () => {
			const previousPhotoId = getPhotoIdFromUrl(previousItem.url);
			if (previousPhotoId) {
				navigateToPhoto(previousPhotoId);
			}
		});
	}

	const nextButton = document.createElement('button');
	nextButton.className = 'detail-view__nav-button detail-view__nav-button--next';
	nextButton.type = 'button';
	nextButton.textContent = 'Next →';
	nextButton.setAttribute('aria-label', 'Show next image');

	if (nextItem === null) {
		nextButton.disabled = true;
	} else {
		nextButton.addEventListener('click', () => {
			const nextPhotoId = getPhotoIdFromUrl(nextItem.url);
			if (nextPhotoId) {
				navigateToPhoto(nextPhotoId);
			}
		});
	}

	nav.appendChild(previousButton);
	nav.appendChild(nextButton);

	const imageContainer = document.createElement('div');
	imageContainer.className = 'detail-view__image-container';

	const img = document.createElement('img');
	img.className = 'detail-view__image';
	img.src = image.url;
	img.alt = getImageTitle(image);

	const caption = document.createElement('div');
	caption.className = 'detail-view__caption';

	const title = document.createElement('h2');
	title.className = 'detail-view__title';
	title.textContent = getImageTitle(image);

	const location = document.createElement('div');
	location.className = 'detail-view__location';
	location.textContent = getImageLocation(image);

	const date = document.createElement('div');
	date.className = 'detail-view__date';
	date.textContent = formatTimestamp(image.captureTs);

	const tags = getImageTags(image);

	caption.appendChild(title);
	caption.appendChild(location);
	caption.appendChild(date);

	if (tags && tags.trim() !== '') {
		const tagsDiv = document.createElement('div');
		tagsDiv.className = 'detail-view__tags';
		tagsDiv.textContent = tags;
		caption.appendChild(tagsDiv);
	}

	imageContainer.appendChild(img);

	detailView.appendChild(closeButton);
	detailView.appendChild(nav);
	detailView.appendChild(imageContainer);
	detailView.appendChild(caption);

	// Allow closing by clicking on the overlay background
	detailView.addEventListener('click', (e) => {
		if (e.target === detailView) {
			navigateToList();
		}
	});

	detailView.hidden = false;
	document.body.style.overflow = 'hidden';

	// Focus close button for accessibility
	closeButton.focus();
}

function hideDetailView() {
	const detailView = document.getElementById('detail-view');
	if (detailView) {
		detailView.hidden = true;
		detailView.innerHTML = '';
	}
	document.body.style.overflow = '';
	document.title = DEFAULT_PAGE_TITLE;
	clearDetailViewMetaTags();
}

function handleRouteChange() {
	const route = getCurrentRoute();

	if (route.type === 'photo') {
		showDetailView(route.photoId);
	} else {
		hideDetailView();
	}
}

// Handle browser back/forward buttons
window.addEventListener('popstate', (event) => {
	handleRouteChange();
});

// Handle Escape key to close detail view
window.addEventListener('keydown', (event) => {
	const target = event.target;
	if (
		target instanceof HTMLInputElement ||
		target instanceof HTMLTextAreaElement ||
		target instanceof HTMLSelectElement ||
		(target instanceof HTMLElement && target.isContentEditable)
	) {
		return;
	}

	if (event.key === 'Escape') {
		if (isSettingsDrawerOpen()) {
			closeSettingsDrawer();
			return;
		}

		const detailView = document.getElementById('detail-view');
		if (detailView && !detailView.hidden) {
			navigateToList();
		}
		return;
	}

	const detailView = document.getElementById('detail-view');
	if (!detailView || detailView.hidden) {
		return;
	}

	if (event.key === 'ArrowLeft') {
		event.preventDefault();
		navigateDetailByOffset(-1);
		return;
	}

	if (event.key === 'ArrowRight') {
		event.preventDefault();
		navigateDetailByOffset(1);
	}
});

// Handle initial load
document.addEventListener('DOMContentLoaded', () => {
	const route = getCurrentRoute();
	if (route.type === 'photo') {
		// Mark any server-rendered meta tags so they can be managed by JavaScript
		markServerRenderedMetaTagsAsDynamic();
	}

	// Log application version from meta tag if present
	try {
		const versionMeta = document.querySelector('meta[name="app-version"]');
		if (versionMeta && versionMeta.content) {
			console.info('Gallery version:', versionMeta.content);
		}
	} catch (e) {
		// Non-fatal
	}
});

initSettingsPanel();
initResponsiveTargetHeight();
run().then(() => {
	// After initial data load, check if we should show a detail view
	const route = getCurrentRoute();
	if (route.type === 'photo') {
		showDetailView(route.photoId);
	}
});
