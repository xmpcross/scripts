#!/usr/bin/env node
/**
 * Airport data ingest — OurAirports edition.
 *
 * Enriches the Strapi `airports` collection from the OurAirports open dataset
 * (public domain): https://ourairports.com/data/ . Richer than the RapidAPI
 * airport-info source — it adds airport TYPE, ELEVATION, and full RUNWAY data
 * (length, surface, lighting, designations), plus municipality, official site
 * and Wikipedia link. No API key, no rate limits, no cost — one bulk pass.
 *
 * Writes a factual `about` (markdown) with an Overview, a "## Runways" section
 * (left column on the airport page) and a "## Airport information" detail block
 * (right column — Type / Elevation / Runways / Municipality / Website /
 * Wikipedia). Also fills empty identity fields (icao, city, country,
 * countryCode, latitude, longitude). Never overwrites curated values.
 *
 * DRY-RUN by default. Add --write to persist.
 *
 * ── env ──   STRAPI_WRITE_TOKEN (for --write), STRAPI_URL (default cms.fxnstudio.com)
 * ── flags ── --write --limit N --only AAA,BBB --sleep MS --force --all --fresh
 *             --refresh-data   (re-download the CSVs even if cached)
 */
import qs from 'qs';
import { readFile, writeFile, mkdir, stat } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const CACHE_DIR = join(__dirname, '.oa-cache');
const CHECKPOINT = join(__dirname, '.ourairports-progress.json');

const AIRPORTS_URL = 'https://davidmegginson.github.io/ourairports-data/airports.csv';
const RUNWAYS_URL = 'https://davidmegginson.github.io/ourairports-data/runways.csv';

const STRAPI_URL = (process.env.STRAPI_URL || 'https://cms.fxnstudio.com').replace(/\/$/, '');
const WRITE_TOKEN = process.env.STRAPI_WRITE_TOKEN || '';
// --openclaw: generate the prose "About" via the self-hosted openclaw gateway
// (OpenAI-compatible). Falls back to templated prose if a call fails.
const OPENCLAW_URL = (process.env.OPENCLAW_URL || 'http://127.0.0.1:18789').replace(/\/$/, '');
const OPENCLAW_TOKEN = process.env.OPENCLAW_TOKEN || '';

const args = process.argv.slice(2);
const has = (f) => args.includes(f);
const val = (f, d) => { const i = args.indexOf(f); return i >= 0 && args[i + 1] ? args[i + 1] : d; };
const OPTS = {
  write: has('--write'),
  limit: Number(val('--limit', '0')) || 0,
  only: (val('--only', '') || '').split(',').map((s) => s.trim().toUpperCase()).filter(Boolean),
  sleep: Number(val('--sleep', '150')) || 150,
  force: has('--force'),
  all: has('--all'),
  fresh: has('--fresh'),
  refreshData: has('--refresh-data'),
  openclaw: has('--openclaw'),
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const regionName = (() => {
  let dn;
  try { dn = new Intl.DisplayNames(['en'], { type: 'region' }); } catch { dn = null; }
  return (code) => { try { return dn ? dn.of(code) : null; } catch { return null; } };
})();

/* ── tiny robust CSV parser (handles quoted fields, commas, "" escapes) ── */
function parseCsv(text) {
  const rows = [];
  let row = [], field = '', inQ = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (inQ) {
      if (c === '"') {
        if (text[i + 1] === '"') { field += '"'; i++; } else inQ = false;
      } else field += c;
    } else if (c === '"') inQ = true;
    else if (c === ',') { row.push(field); field = ''; }
    else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
    else if (c === '\r') { /* skip */ }
    else field += c;
  }
  if (field.length || row.length) { row.push(field); rows.push(row); }
  const header = rows.shift();
  return rows.filter((r) => r.length > 1).map((r) => Object.fromEntries(header.map((h, i) => [h, r[i]])));
}

async function loadCsv(url, file) {
  await mkdir(CACHE_DIR, { recursive: true });
  const path = join(CACHE_DIR, file);
  let fresh = false;
  if (!OPTS.refreshData) {
    try { const s = await stat(path); fresh = Date.now() - s.mtimeMs < 7 * 864e5; } catch { /* miss */ }
  }
  if (!fresh) {
    process.stdout.write(`Downloading ${file}… `);
    const res = await fetch(url);
    if (!res.ok) throw new Error(`${url} -> ${res.status}`);
    await writeFile(path, await res.text());
    console.log('done');
  }
  return parseCsv(await readFile(path, 'utf8'));
}

/* ── Strapi ── */
async function strapiGet(path, params) {
  const q = params ? '?' + qs.stringify(params, { encodeValuesOnly: true }) : '';
  const res = await fetch(`${STRAPI_URL}/api/${path}${q}`, { headers: { 'Content-Type': 'application/json' } });
  if (!res.ok) throw new Error(`Strapi GET ${path} -> ${res.status}`);
  return res.json();
}
async function strapiUpdateAirport(documentId, data) {
  const res = await fetch(`${STRAPI_URL}/api/airports/${documentId}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${WRITE_TOKEN}` },
    body: JSON.stringify({ data }),
  });
  if (!res.ok) throw new Error(`Strapi PUT airports/${documentId} -> ${res.status}: ${await res.text().catch(() => '')}`);
  return res.json();
}
async function fetchAllAirports() {
  const fields = ['iata', 'icao', 'name', 'city', 'country', 'countryCode', 'region', 'latitude', 'longitude', 'timezone', 'about'];
  const all = [];
  let page = 1;
  for (;;) {
    const r = await strapiGet('airports', { fields, sort: ['iata:asc'], pagination: { page, pageSize: 100 } });
    all.push(...r.data);
    if (page >= (r.meta?.pagination?.pageCount ?? 1)) break;
    page++;
  }
  return all;
}

/* ── field mapping + about builder ── */
const str = (v) => (typeof v === 'string' && v.trim() ? v.trim() : null);
const numf = (v) => { const n = parseFloat(v); return Number.isFinite(n) ? n : null; };
const ftToM = (ft) => Math.round(ft * 0.3048);

const TYPE_LABEL = {
  large_airport: 'Large airport', medium_airport: 'Medium airport', small_airport: 'Small airport',
  heliport: 'Heliport', seaplane_base: 'Seaplane base', balloonport: 'Balloonport', closed: 'Closed airport',
};
const SURFACE = {
  ASP: 'asphalt', CON: 'concrete', PEM: 'paved', BIT: 'bituminous', GRS: 'grass', GRE: 'gravel',
  GVL: 'gravel', TURF: 'turf', DIRT: 'dirt', WATER: 'water', SNOW: 'snow', SAND: 'sand', COP: 'composite',
};
const surfaceLabel = (s) => {
  if (!s) return null;
  const key = s.toUpperCase().replace(/[^A-Z]/g, '');
  return SURFACE[key] || s.toLowerCase();
};

function runwayLines(runways) {
  return runways
    .filter((r) => r.closed !== '1')
    .map((r) => {
      const desig = [str(r.le_ident), str(r.he_ident)].filter(Boolean).join('/');
      const lenFt = numf(r.length_ft);
      const surf = surfaceLabel(str(r.surface));
      const lit = r.lighted === '1';
      const parts = [];
      if (lenFt) parts.push(`${lenFt.toLocaleString()} ft (${ftToM(lenFt).toLocaleString()} m)`);
      if (surf) parts.push(surf);
      if (lit) parts.push('lighted');
      return `Runway ${desig || '—'}${parts.length ? ' — ' + parts.join(', ') : ''}.`;
    });
}

/** The "## Airport information" detail block (right column of the page). */
function infoBlock(oa, runways) {
  const elevFt = numf(oa.elevation_ft);
  const openRunways = runways.filter((r) => r.closed !== '1');
  const info = [
    TYPE_LABEL[oa.type] ? `**Type:** ${TYPE_LABEL[oa.type]}` : '',
    elevFt != null ? `**Elevation:** ${elevFt.toLocaleString()} ft (${ftToM(elevFt).toLocaleString()} m)` : '',
    openRunways.length ? `**Runways:** ${openRunways.length}` : '',
    str(oa.municipality) ? `**Municipality:** ${oa.municipality}` : '',
    str(oa.home_link) ? `**Website:** ${oa.home_link}` : '',
    str(oa.wikipedia_link) ? `**Wikipedia:** ${oa.wikipedia_link}` : '',
  ].filter(Boolean);
  return info.length ? `## Airport information\n\n` + info.join('  \n') : '';
}

/** Compact, accurate fact sheet handed to openclaw to ground its prose. */
function factsString(oa, runways, existing) {
  const open = runways.filter((r) => r.closed !== '1');
  return [
    `- Name: ${str(oa.name) || existing.name}`,
    `- IATA: ${existing.iata}${str(oa.icao_code) ? `, ICAO: ${oa.icao_code}` : ''}`,
    `- Type: ${(TYPE_LABEL[oa.type] || 'airport').toLowerCase()}`,
    `- Serves: ${[str(oa.municipality), regionName(str(oa.iso_country)) || existing.country].filter(Boolean).join(', ')}`,
    numf(oa.elevation_ft) != null ? `- Elevation: ${numf(oa.elevation_ft)} ft (${ftToM(numf(oa.elevation_ft))} m)` : '',
    open.length ? `- Runways: ${open.map((r) => `${[str(r.le_ident), str(r.he_ident)].filter(Boolean).join('/')} (${numf(r.length_ft) ? numf(r.length_ft) + ' ft' : 'length n/a'}${surfaceLabel(str(r.surface)) ? ', ' + surfaceLabel(str(r.surface)) : ''}${r.lighted === '1' ? ', lighted' : ''})`).join('; ')}` : '',
    str(existing.timezone) ? `- Time zone: ${existing.timezone}` : '',
    str(oa.home_link) ? `- Official site: ${oa.home_link}` : '',
  ].filter(Boolean).join('\n');
}

/** Generate the prose About via the openclaw OpenAI-compatible endpoint. */
async function genProse(facts) {
  const sys = 'You are a precise travel and aviation content writer. Output ONLY the requested content as plain markdown — no preamble, no closing remarks, no code fences. Use only the facts provided plus well-established general knowledge about the place; do NOT invent passenger numbers, terminal counts, airline names, opening dates, or any history you are unsure of.';
  const user = `Write an informative "About the airport" section for a travel website airport page, about 200-260 words total. Structure it as 2-4 short sections, each with a "## " markdown subheading.\n\nRules:\n- Each section must be a SINGLE concise paragraph.\n- EXACTLY ONE of the sections must present its content as a markdown bullet list (each line starting with "- "), 3-6 bullets, instead of a paragraph.\n- Use plain text only: no bold (** **), no backticks or inline code, no tables.\n- Make it genuinely useful for visitors and travellers; weave facts into readable prose.\n\nGrounded facts (accurate):\n${facts}\n\nReturn only the About section.`;
  const res = await fetch(`${OPENCLAW_URL}/v1/chat/completions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${OPENCLAW_TOKEN}` },
    body: JSON.stringify({ model: 'openclaw', messages: [{ role: 'system', content: sys }, { role: 'user', content: user }] }),
  });
  if (!res.ok) throw new Error(`openclaw ${res.status}: ${(await res.text().catch(() => '')).slice(0, 200)}`);
  const j = await res.json();
  const content = j?.choices?.[0]?.message?.content;
  if (!content || !content.trim()) throw new Error('openclaw returned empty content');
  return content.trim().replace(/^```(?:markdown)?\s*/i, '').replace(/\s*```$/i, '').trim();
}

function buildAbout(oa, runways, existing, prose) {
  // openclaw mode: rich generated prose (left column) + structured info block (right column)
  if (prose) return [prose, infoBlock(oa, runways)].filter(Boolean).join('\n\n');

  const name = str(oa.name) || existing.name;
  const typeLabel = TYPE_LABEL[oa.type] || 'airport';
  const place = [str(oa.municipality), regionName(str(oa.iso_country)) || existing.country].filter(Boolean).join(', ');
  const elevFt = numf(oa.elevation_ft);

  const overview = [
    `${name} is a ${typeLabel.toLowerCase()}${place ? ` serving ${place}` : ''}.`,
    str(oa.icao_code) ? `It carries the IATA code ${existing.iata} and ICAO code ${oa.icao_code}.` : '',
    elevFt != null ? `The airport sits at an elevation of ${elevFt.toLocaleString()} ft (${ftToM(elevFt).toLocaleString()} m).` : '',
  ].filter(Boolean).join(' ');

  const openRunways = runways.filter((r) => r.closed !== '1');
  let md = overview;
  if (openRunways.length) {
    const lengths = openRunways.map((r) => numf(r.length_ft)).filter((n) => n != null);
    const maxFt = lengths.length ? Math.max(...lengths) : null;
    const summary = `${name} has ${openRunways.length} runway${openRunways.length === 1 ? '' : 's'}${maxFt ? `, the longest measuring ${maxFt.toLocaleString()} ft (${ftToM(maxFt).toLocaleString()} m)` : ''}.`;
    md += `\n\n## Runways\n\n${summary}\n\n` + runwayLines(openRunways).join('\n\n');
  }
  const block = infoBlock(oa, runways);
  if (block) md += `\n\n${block}`;
  return md;
}

function buildUpdate(airport, oa, runways, prose) {
  const data = {};
  const fillIfEmpty = (k, v) => {
    if (v == null) return;
    const cur = airport[k];
    if (cur == null || (typeof cur === 'string' && !cur.trim())) data[k] = v;
  };
  fillIfEmpty('icao', str(oa.icao_code) || str(oa.gps_code));
  fillIfEmpty('city', str(oa.municipality));
  fillIfEmpty('countryCode', str(oa.iso_country));
  fillIfEmpty('country', regionName(str(oa.iso_country)));
  fillIfEmpty('latitude', numf(oa.latitude_deg));
  fillIfEmpty('longitude', numf(oa.longitude_deg));

  if (OPTS.force || !str(airport.about)) data.about = buildAbout(oa, runways, airport, prose);
  return Object.keys(data).length ? data : null;
}

/* ── checkpoint ── */
async function loadCp() { if (OPTS.fresh) return { processed: {} }; try { return JSON.parse(await readFile(CHECKPOINT, 'utf8')); } catch { return { processed: {} }; } }
async function saveCp(cp) { await writeFile(CHECKPOINT, JSON.stringify(cp, null, 2)); }

function isNeedy(a) {
  if (OPTS.all || OPTS.force) return true;
  return !str(a.about) || !str(a.icao) || a.latitude == null || a.longitude == null;
}

async function main() {
  if (OPTS.write && !WRITE_TOKEN) { console.error('FATAL: --write requires STRAPI_WRITE_TOKEN.'); process.exit(1); }
  if (OPTS.openclaw && !OPENCLAW_TOKEN) { console.error('FATAL: --openclaw requires OPENCLAW_TOKEN.'); process.exit(1); }
  console.log(`Mode: ${OPTS.write ? 'WRITE' : 'DRY-RUN'}${OPTS.openclaw ? ' + openclaw prose' : ''} | strapi: ${STRAPI_URL}`);

  const [airportRows, runwayRows] = await Promise.all([loadCsv(AIRPORTS_URL, 'airports.csv'), loadCsv(RUNWAYS_URL, 'runways.csv')]);
  const oaByIata = new Map();
  for (const r of airportRows) { const ia = (r.iata_code || '').toUpperCase(); if (ia) oaByIata.set(ia, r); }
  const rwByIdent = new Map();
  for (const r of runwayRows) { const id = r.airport_ident; if (!id) continue; (rwByIdent.get(id) || rwByIdent.set(id, []).get(id)).push(r); }
  console.log(`OurAirports: ${oaByIata.size} airports with IATA, ${runwayRows.length} runways.\n`);

  const cp = await loadCp();
  let airports = await fetchAllAirports();
  console.log(`Strapi airports: ${airports.length}`);
  airports = OPTS.only.length ? airports.filter((a) => OPTS.only.includes((a.iata || '').toUpperCase())) : airports.filter(isNeedy);
  if (!OPTS.only.length && !OPTS.force) airports = airports.filter((a) => !cp.processed[a.iata]);
  if (OPTS.limit) airports = airports.slice(0, OPTS.limit);
  console.log(`Candidates this run: ${airports.length}\n`);

  const stats = { updated: 0, wouldUpdate: 0, noMatch: 0, noChange: 0, errors: 0 };
  for (let i = 0; i < airports.length; i++) {
    const a = airports[i];
    const iata = (a.iata || '').toUpperCase();
    const tag = `[${i + 1}/${airports.length}] ${iata}`;
    const oa = oaByIata.get(iata);
    if (!oa) { stats.noMatch++; cp.processed[iata] = { status: 'no_match' }; console.log(`${tag} not in OurAirports`); continue; }
    const runways = rwByIdent.get(oa.ident) || [];
    try {
      let prose = null;
      if (OPTS.openclaw && (OPTS.force || !str(a.about))) {
        try { prose = await genProse(factsString(oa, runways, a)); }
        catch (e) { console.warn(`${tag} openclaw failed (${e.message}) — falling back to templated prose`); }
      }
      const update = buildUpdate(a, oa, runways, prose);
      if (!update) { stats.noChange++; cp.processed[iata] = { status: 'no_change' }; console.log(`${tag} nothing to fill`); }
      else if (OPTS.write) {
        await strapiUpdateAirport(a.documentId, update);
        stats.updated++; cp.processed[iata] = { status: 'updated', fields: Object.keys(update) };
        console.log(`${tag} updated: ${Object.keys(update).join(', ')} (${runways.filter((r)=>r.closed!=='1').length} runways)`);
      } else {
        stats.wouldUpdate++;
        console.log(`${tag} would set: ${Object.keys(update).join(', ')} (${runways.filter((r)=>r.closed!=='1').length} runways)`);
        if (i === 0) console.log('   about preview:\n   ' + (update.about || '').split('\n').join('\n   '));
      }
    } catch (e) {
      // Do NOT checkpoint errors — leaving them out means the next run retries
      // them instead of skipping permanently.
      stats.errors++;
      console.error(`${tag} ERROR (will retry next run): ${e.message}`);
    }
    await saveCp(cp);
    if (OPTS.write && i < airports.length - 1) await sleep(OPTS.sleep);
  }
  console.log('\n──────── summary ────────');
  console.log(stats);
  if (!OPTS.write) console.log('DRY-RUN — nothing written. Add --write (+ STRAPI_WRITE_TOKEN) to persist.');
}
main().catch((e) => { console.error('Fatal:', e); process.exit(1); });
