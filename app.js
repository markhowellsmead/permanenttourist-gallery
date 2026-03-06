const LOCALE = 'en-GB';

function parseExifDateTime(value) {
	if (typeof value !== 'string') {
		return null;
	}

	const match = value.match(/^(\d{4}):(\d{2}):(\d{2})\s+(\d{2}):(\d{2}):(\d{2})$/);
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

	const dateMatch = dateValue.match(/^(\d{4})(\d{2})(\d{2})$/);
	if (!dateMatch) {
		return null;
	}

	let timePart = '00:00:00';
	if (typeof timeValue === 'string') {
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
const DEFAULT_PAGE_TITLE = document.title;
let selectedMonthYear = '';
let selectedCountry = '';

function setShowCaptionsEnabled(enabled) {
	document.body.classList.toggle('show-captions', enabled);
}

function createCaptionSettingsPanel() {
	const status = document.getElementById('status');
	if (!status || !status.parentNode) {
		return null;
	}

	const panel = document.createElement('div');
	panel.id = 'settings-panel';
	panel.setAttribute('aria-label', 'Gallery settings');

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

	const resetButton = document.createElement('button');
	resetButton.type = 'button';
	resetButton.id = 'filters-reset-button';
	resetButton.textContent = 'Reset filters';

	panel.appendChild(label);
	panel.appendChild(monthYearLabel);
	panel.appendChild(monthYearSelect);
	panel.appendChild(countryLabel);
	panel.appendChild(countrySelect);
	panel.appendChild(resetButton);

	status.parentNode.insertBefore(panel, status);

	return {
		checkbox,
		monthYearSelect,
		countrySelect,
		resetButton,
	};
}

function initSettingsPanel() {
	const settings = createCaptionSettingsPanel();
	if (!settings) {
		return;
	}

	const checkbox = settings.checkbox;
	const monthYearSelect = settings.monthYearSelect;
	const countrySelect = settings.countrySelect;
	const resetButton = settings.resetButton;
	if (!(checkbox instanceof HTMLInputElement)) {
		return;
	}

	let enabled = false;
	try {
		enabled = localStorage.getItem(SHOW_CAPTIONS_STORAGE_KEY) === '1';
	} catch (_error) {
		enabled = false;
	}

	checkbox.checked = enabled;
	setShowCaptionsEnabled(enabled);

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
			run();
		});
	}

	if (countrySelect instanceof HTMLSelectElement) {
		countrySelect.addEventListener('change', () => {
			selectedCountry = countrySelect.value;
			run();
		});
	}

	if (
		resetButton instanceof HTMLButtonElement &&
		monthYearSelect instanceof HTMLSelectElement &&
		countrySelect instanceof HTMLSelectElement
	) {
		const updateResetButtonVisibility = () => {
			const hasActiveFilter = monthYearSelect.value !== '' || countrySelect.value !== '';
			resetButton.hidden = !hasActiveFilter;
		};

		updateResetButtonVisibility();

		monthYearSelect.addEventListener('change', updateResetButtonVisibility);
		countrySelect.addEventListener('change', updateResetButtonVisibility);

		resetButton.addEventListener('click', () => {
			selectedMonthYear = '';
			selectedCountry = '';
			monthYearSelect.value = '';
			countrySelect.value = '';
			updateResetButtonVisibility();
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

function populateMonthYearFilterOptions(items) {
	const monthYearSelect = document.getElementById('month-year-filter-select');
	if (!(monthYearSelect instanceof HTMLSelectElement)) {
		return;
	}

	const monthYears = new Set();
	for (const item of items) {
		const captureTs = getCaptureTimestamp(item);
		const key = getMonthYearKeyFromTimestamp(captureTs);
		if (key !== null) {
			monthYears.add(key);
		}
	}

	const sortedMonthYears = Array.from(monthYears).sort((a, b) => b.localeCompare(a, LOCALE));

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

function populateCountryFilterOptions(items) {
	const countrySelect = document.getElementById('country-filter-select');
	if (!(countrySelect instanceof HTMLSelectElement)) {
		return;
	}

	const countries = new Set();
	for (const item of items) {
		const terms = getLocationTerms(item);
		for (const term of terms) {
			countries.add(term);
		}
	}

	const sortedCountries = Array.from(countries).sort((a, b) => a.localeCompare(b, LOCALE));

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

async function fetchImageData(monthYear = '', country = '') {
	const url = new URL('/api', window.location.origin);
	if (monthYear !== '') {
		url.searchParams.set('month_year', monthYear);
	}
	if (country !== '') {
		url.searchParams.set('country', country);
	}

	const response = await fetch(url.toString(), {
		method: 'GET',
	});

	if (!response.ok) {
		throw new Error(`API returned ${response.status}`);
	}

	const data = await response.json();
	if (!Array.isArray(data)) {
		throw new Error('Unexpected API response format.');
	}

	return data;
}

function renderImages(images) {
	const list = document.getElementById('image-list');
	list.innerHTML = '';

	for (const item of images) {
		const li = document.createElement('li');
		li.className = 'image-item c-grid500__item';

		const metrics = getGridMetrics(item);
		li.style.flexGrow = String(metrics.flexGrow);
		li.style.setProperty('--item-ratio', String(metrics.ratio));
		li.style.flexBasis = 'calc(var(--grid-target-height, 320px) * var(--item-ratio))';

		const card = document.createElement('div');
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

		// Add click handler for detail view
		card.style.cursor = 'pointer';
		card.addEventListener('click', () => {
			const photoId = getPhotoIdFromUrl(item.url);
			if (photoId) {
				navigateToPhoto(photoId);
			}
		});

		list.appendChild(li);
	}
}

async function run() {
	const status = document.getElementById('status');

	try {
		const data = await fetchImageData(selectedMonthYear, selectedCountry);

		const monthYearSelect = document.getElementById('month-year-filter-select');
		if (monthYearSelect instanceof HTMLSelectElement && monthYearSelect.options.length <= 1) {
			populateMonthYearFilterOptions(data);
		}

		const countrySelect = document.getElementById('country-filter-select');
		if (countrySelect instanceof HTMLSelectElement && countrySelect.options.length <= 1) {
			populateCountryFilterOptions(data);
		}

		const withCaptureDates = data.map((item) => ({
			...item,
			captureTs: getCaptureTimestamp(item),
		}));

		withCaptureDates.sort((a, b) => {
			const aTs = a.captureTs;
			const bTs = b.captureTs;

			if (aTs === null && bTs === null) return 0;
			if (aTs === null) return 1;
			if (bTs === null) return -1;
			return bTs - aTs;
		});

		// Cache all images for SPA navigation
		allImagesCache = withCaptureDates;

		renderImages(withCaptureDates);
		const imageCount = withCaptureDates.length;
		const imageNoun = imageCount === 1 ? 'image' : 'images';
		const imageLabel = `Found ${imageCount} ${imageNoun}`;
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
		} else {
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
	} catch (error) {
		status.textContent = `Failed to load images: ${error.message}`;
	}
}

// SPA functionality
let allImagesCache = [];

function getPhotoIdFromUrl(imageUrl) {
	if (typeof imageUrl !== 'string') {
		return null;
	}

	// Extract filename without extension from URL like "/media/20170919-_DSF1840.jpg"
	const match = imageUrl.match(/\/([^/]+)\.(jpe?g|png|gif|webp)$/i);
	return match ? match[1] : null;
}

function findImageByPhotoId(photoId) {
	return allImagesCache.find((item) => {
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

function showDetailView(photoId) {
	const image = findImageByPhotoId(photoId);
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
	detailView.appendChild(imageContainer);
	detailView.appendChild(caption);

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
	if (event.key === 'Escape') {
		const detailView = document.getElementById('detail-view');
		if (detailView && !detailView.hidden) {
			navigateToList();
		}
	}
});

// Handle initial load
document.addEventListener('DOMContentLoaded', () => {
	const route = getCurrentRoute();
	if (route.type === 'photo') {
		// We need to wait for images to load before showing detail view
		// The detail view will be shown after data loads
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
