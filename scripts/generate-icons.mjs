// Generates the Blood & Banners emblem icons for the MAUI app, the Blazor web app and the legacy static pages.
// Usage: node scripts/generate-icons.mjs   (needs Chrome or Edge installed to rasterize the PNGs)
import { writeFileSync, mkdtempSync, rmSync, existsSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { join, resolve } from "node:path";
import { tmpdir } from "node:os";

const root = resolve(import.meta.dirname, "..");
const CRIMSON = "#681E24";
const GOLD = "#F0CF7A";
const GOLD_SHADE = "#C9A35A";
const GOLD_DARK = "#8C6A33";
const BLOOD = "#C8141E";
const BLOOD_SHINE = "#F05A4E";

// Emblem drawn in a 512x512 design box: spear-tipped pole with a swallow-tailed banner bearing a blood drop.
// Visual bounds are roughly x 128..392, y 40..452, centred on (260, 246).
const EMBLEM = `
    <path d="M159 40 L181 98 L137 98 Z" fill="${GOLD}"/>
    <rect x="128" y="98" width="62" height="16" rx="4" fill="${GOLD_SHADE}"/>
    <rect x="150" y="114" width="18" height="326" fill="${GOLD_SHADE}"/>
    <path d="M150 440 L168 440 L159 452 Z" fill="${GOLD_SHADE}"/>
    <path d="M168 126 C228 108 300 142 392 122 L332 212 L392 302 C300 322 228 288 168 306 Z" fill="${GOLD}"/>
    <path d="M168 270 C228 252 300 286 368 268 L392 302 C300 322 228 288 168 306 Z" fill="${GOLD_SHADE}"/>
    <path d="M254 158 C254 158 220 204 220 230 A34 34 0 0 0 288 230 C288 204 254 158 254 158 Z" fill="${BLOOD}"/>
    <path d="M236 222 C236 210 242 200 248 192 C246 204 244 214 246 226 Z" fill="${BLOOD_SHINE}"/>
    <rect x="150" y="114" width="18" height="326" fill="${GOLD_DARK}" opacity="0.35"/>`;

// Places the emblem so its visual centre lands on (cx, cy) at the given scale.
const placed = (scale, cx, cy) =>
    `<g transform="translate(${cx - 260 * scale} ${cy - 246 * scale}) scale(${scale})">${EMBLEM}\n    </g>`;

const svg = (size, body) =>
    `<?xml version="1.0" encoding="UTF-8"?>\n<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg">\n    ${body}\n</svg>\n`;

// Browser favicon: emblem on a rounded crimson tile, emblem large so it reads at 16px.
const favicon = svg(512, `<rect width="512" height="512" rx="96" fill="${CRIMSON}"/>\n    ${placed(1.08, 256, 256)}`);
// PWA maskable icon: full-bleed crimson, emblem inside the 80% safe zone.
const maskable = svg(512, `<rect width="512" height="512" fill="${CRIMSON}"/>\n    ${placed(0.82, 256, 256)}`);

const outputs = {
    "src/BloodAndBanners.Maui/Resources/AppIcon/appicon.svg": svg(456, `<rect width="456" height="456" fill="${CRIMSON}"/>`),
    // Adaptive-icon foreground: kept inside the central 66% so launcher masks never clip it.
    "src/BloodAndBanners.Maui/Resources/AppIcon/appiconfg.svg": svg(456, placed(0.64, 228, 228)),
    "src/BloodAndBanners.Maui/Resources/Splash/splash.svg": svg(456, placed(1.0, 228, 228)),
    "src/BloodAndBanners.Maui/wwwroot/favicon.svg": favicon,
    "src/BloodAndBanners.Web/wwwroot/favicon.svg": favicon,
    "images/favicon.svg": favicon,
};
for (const [path, content] of Object.entries(outputs)) writeFileSync(join(root, path), content);

const browser = [
    "C:/Program Files/Google/Chrome/Application/chrome.exe",
    "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
    "/usr/bin/google-chrome",
].find(existsSync);
if (!browser) throw new Error("Chrome or Edge is needed to rasterize the PNG icons.");

const work = mkdtempSync(join(tmpdir(), "bb-icons-"));
const rasterize = (content, size, out) => {
    const page = join(work, "icon.html");
    writeFileSync(page, `<!doctype html><style>html,body{margin:0;background:transparent}img{display:block}</style>` +
        `<img width="${size}" height="${size}" src="data:image/svg+xml;base64,${Buffer.from(content).toString("base64")}">`);
    execFileSync(browser, ["--headless", "--disable-gpu", "--hide-scrollbars", "--default-background-color=00000000",
        `--user-data-dir=${join(work, "profile")}`, `--window-size=${size},${size}`, `--screenshot=${join(root, out)}`,
        "file:///" + page.replace(/\\/g, "/")], { stdio: "ignore" });
};

rasterize(favicon, 32, "src/BloodAndBanners.Web/wwwroot/favicon.png");
rasterize(favicon, 192, "src/BloodAndBanners.Web/wwwroot/icon-192.png");
rasterize(favicon, 512, "src/BloodAndBanners.Web/wwwroot/icon-512.png");
rasterize(maskable, 512, "src/BloodAndBanners.Web/wwwroot/icon-maskable-512.png");
rmSync(work, { recursive: true, force: true });
console.log("Icons generated.");
