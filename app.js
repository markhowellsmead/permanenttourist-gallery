function getByPath(obj, path) {
    return path.reduce(
        (acc, key) =>
            acc && Object.prototype.hasOwnProperty.call(acc, key)
                ? acc[key]
                : null,
        obj,
    );
}

function parseExifDateTime(value) {
    if (typeof value !== "string") {
        return null;
    }

    const match = value.match(
        /^(\d{4}):(\d{2}):(\d{2})\s+(\d{2}):(\d{2}):(\d{2})$/,
    );
    if (!match) {
        return null;
    }

    const iso = `${match[1]}-${match[2]}-${match[3]}T${match[4]}:${match[5]}:${match[6]}`;
    const ts = Date.parse(iso);
    return Number.isNaN(ts) ? null : ts;
}

function parseIptcDateTime(dateValue, timeValue) {
    if (typeof dateValue !== "string") {
        return null;
    }

    const dateMatch = dateValue.match(/^(\d{4})(\d{2})(\d{2})$/);
    if (!dateMatch) {
        return null;
    }

    let timePart = "00:00:00";
    if (typeof timeValue === "string") {
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
        ["exif", "exif", "datetimeoriginal"],
        ["exif", "exif", "datetimedigitized"],
        ["exif", "ifd0", "datetime"],
    ];

    for (const path of exifCandidates) {
        const raw = getByPath(item, path);
        const ts = parseExifDateTime(raw);
        if (ts !== null) {
            return ts;
        }
    }

    const iptcDate = getByPath(item, ["iptc", "date_created", "value", 0]);
    const iptcTime = getByPath(item, ["iptc", "time_created", "value", 0]);
    return parseIptcDateTime(iptcDate, iptcTime);
}

function formatTimestamp(ts) {
    if (ts === null) {
        return "Unknown capture date";
    }

    return new Date(ts).toLocaleString();
}

function getFirstIptcValue(item, key) {
    const value = getByPath(item, ["iptc", key, "value", 0]);
    return typeof value === "string" && value.trim() !== ""
        ? value.trim()
        : null;
}

function getImageTitle(item) {
    return getFirstIptcValue(item, "object_name") ?? "Untitled";
}

function getImageLocation(item) {
    const parts = [
        getFirstIptcValue(item, "sublocation"),
        getFirstIptcValue(item, "city"),
        getFirstIptcValue(item, "state_province"),
        getFirstIptcValue(item, "country_primary_location_name"),
    ].filter(Boolean);

    if (parts.length === 0) {
        return "Unknown location";
    }

    return parts.join(", ");
}

function getImageTags(item) {
    const tags = getByPath(item, ["iptc", "keywords", "value"]);
    if (!Array.isArray(tags)) {
        return "";
    }

    const cleanTags = tags
        .filter(tag => typeof tag === "string")
        .map(tag => tag.trim())
        .filter(tag => tag !== "");

    return cleanTags.join(", ");
}

function renderImages(images) {
    const list = document.getElementById("image-list");
    list.innerHTML = "";

    for (const item of images) {
        const li = document.createElement("li");
        li.className = "image-item";

        const figure = document.createElement("figure");
        figure.className = "image-figure";

        const img = document.createElement("img");
        img.src = item.url;
        img.alt = item.url;
        img.loading = "lazy";

        const meta = document.createElement("div");
        meta.className = "meta";

        const date = document.createElement("div");
        date.className = "date";
        date.textContent = formatTimestamp(item.captureTs);

        const title = document.createElement("div");
        title.className = "title";
        title.textContent = getImageTitle(item);

        const location = document.createElement("div");
        location.className = "location";
        location.textContent = getImageLocation(item);

        const tags = document.createElement("div");
        tags.className = "tags";
        tags.textContent = getImageTags(item);

        meta.appendChild(title);
        meta.appendChild(location);
        meta.appendChild(tags);
        meta.appendChild(date);
        figure.appendChild(img);
        li.appendChild(figure);
        li.appendChild(meta);
        list.appendChild(li);
    }
}

async function run() {
    const status = document.getElementById("status");

    try {
        const response = await fetch("/api", {
            method: "GET",
        });
        if (!response.ok) {
            throw new Error(`API returned ${response.status}`);
        }

        const data = await response.json();
        if (!Array.isArray(data)) {
            throw new Error("Unexpected API response format.");
        }

        const withCaptureDates = data.map(item => ({
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
        status.textContent = `Loaded ${withCaptureDates.length} images.`;
    } catch (error) {
        status.textContent = `Failed to load images: ${error.message}`;
    }
}

run();
