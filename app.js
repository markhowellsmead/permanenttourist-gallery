function getByPath(obj, path) {
	return path.reduce((acc, key) => (acc && Object.prototype.hasOwnProperty.call(acc, key) ? acc[key] : null), obj);
}

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
	const exifCandidates = [
		['exif', 'exif', 'datetimeoriginal'],
		['exif', 'exif', 'datetimedigitized'],
		['exif', 'ifd0', 'datetime'],
	];

	for (const path of exifCandidates) {
		const raw = getByPath(item, path);
		const ts = parseExifDateTime(raw);
		if (ts !== null) {
			return ts;
		}
	}

	const iptcDate = getByPath(item, ['iptc', 'date_created', 'value', 0]);
	const iptcTime = getByPath(item, ['iptc', 'time_created', 'value', 0]);
	return parseIptcDateTime(iptcDate, iptcTime);
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

function getFirstIptcValue(item, key) {
	const value = getByPath(item, ['iptc', key, 'value', 0]);
	return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function getImageTitle(item) {
	return getFirstIptcValue(item, 'object_name') ?? 'Untitled';
}

function getImageLocation(item) {
	const country = getFirstIptcValue(item, 'country_primary_location_name');
	const includeCountry = typeof country === 'string' && country.trim().toLowerCase() !== 'united kingdom';

	const parts = [
		getFirstIptcValue(item, 'sublocation'),
		getFirstIptcValue(item, 'city'),
		getFirstIptcValue(item, 'state_province'),
		includeCountry ? country : null,
	].filter(Boolean);

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
	const tags = getByPath(item, ['iptc', 'keywords', 'value']);
	if (!Array.isArray(tags)) {
		return '';
	}

	const cleanTags = tags
		.filter((tag) => typeof tag === 'string')
		.map((tag) => tag.trim())
		.filter((tag) => tag !== '');

	return cleanTags.join(', ');
}

function getImageDimensions(item) {
	const width = Number(getByPath(item, ['exif', 'computed', 'width']));
	const height = Number(getByPath(item, ['exif', 'computed', 'height']));

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

function getGridMetrics(item, targetHeight) {
	const dimensions = getImageDimensions(item);
	const width = dimensions.width;
	const height = dimensions.height;

	return {
		flexGrow: (width * 100) / height,
		flexBasis: (width * targetHeight) / height,
		paddingBottom: (height / width) * 100,
	};
}

const SHOW_CAPTIONS_STORAGE_KEY = 'gallery.showCaptions';
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
	label.appendChild(document.createTextNode(' Keep image captions visible'));

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
	const country = getFirstIptcValue(item, 'country_primary_location_name');
	const terms = [];

	const isUnitedKingdom = typeof country === 'string' && country.trim().toLowerCase() === 'united kingdom';

	if (typeof country === 'string' && country.trim() !== '' && !isUnitedKingdom) {
		terms.push(country.trim());
	}

	if (isUnitedKingdom) {
		const regionalTerms = [getFirstIptcValue(item, 'state_province'), getFirstIptcValue(item, 'sublocation')].filter(
			(term) => typeof term === 'string' && term.trim() !== '',
		);

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
	const targetHeight = 320;

	for (const item of images) {
		const li = document.createElement('li');
		li.className = 'image-item c-grid500__item';

		const metrics = getGridMetrics(item, targetHeight);
		li.style.flexGrow = String(metrics.flexGrow);
		li.style.flexBasis = `${metrics.flexBasis}px`;

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
		figure.appendChild(img);
		card.appendChild(uncollapse);
		card.appendChild(figure);
		card.appendChild(meta);
		li.appendChild(card);
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

initSettingsPanel();
run();
