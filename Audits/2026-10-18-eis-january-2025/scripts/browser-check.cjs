#!/usr/bin/env node
'use strict';

// Independent reconnaissance only. Never emits the proxy URL or raw error messages.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { chromium } = require('@playwright/test');

const root = path.resolve(__dirname, '../../..');
const output = path.resolve(__dirname, '../evidence/browser-check.json');
const hash = (body) => crypto.createHash('sha256').update(body).digest('hex');
const evidence = {
    started_at_utc: new Date().toISOString(),
    status: 'not_verified',
    scope: 'EIS 44-FZ completed, OKPD2 43, publication January 2025 ascending, price RUB 0..1000000000000',
    max_main_requests: 4,
    request_timeout_ms: 30000,
    minimum_pause_ms: 1600,
    request_count: 0,
    tls: 'Reconnaissance browser context ignoreHTTPSErrors; production client trust unchanged',
};

function proxyConfiguration() {
    const env = fs.readFileSync(path.join(root, '.env'), 'utf8');
    const match = env.match(/^PLAYWRIGHT_MCP_PROXY_SERVER\s*=\s*(.*)$/m);
    if (!match) throw new Error('proxy_missing');
    const raw = match[1].trim().replace(/^(['"])(.*)\1$/, '$2');
    const url = new URL(raw);
    if (!['http:', 'https:', 'socks5:'].includes(url.protocol)) throw new Error('proxy_protocol');
    return {
        server: `${url.protocol}//${url.hostname}:${url.port}`,
        username: decodeURIComponent(url.username),
        password: decodeURIComponent(url.password),
    };
}

// RFC4180-style parser: quoted newlines and escaped quotes remain inside the same record.
function csvRows(body) {
    const records = [];
    let row = [], field = '', quoted = false;
    for (let i = 0; i < body.length; i++) {
        const ch = body[i];
        if (ch === '"') {
            if (quoted && body[i + 1] === '"') { field += '"'; i++; }
            else quoted = !quoted;
        } else if (ch === ';' && !quoted) {
            row.push(field); field = '';
        } else if ((ch === '\r' || ch === '\n') && !quoted) {
            if (ch === '\r' && body[i + 1] === '\n') i++;
            row.push(field);
            if (row.some(value => value !== '')) records.push(row);
            row = []; field = '';
        } else field += ch;
    }
    if (quoted) throw new Error('csv_unclosed_quote');
    if (field !== '' || row.length) { row.push(field); records.push(row); }
    return records;
}

let browser;
let phase = 'initialization';
(async () => {
    try {
        const executablePath = [
            '/home/vostrikov/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome',
            '/home/vostrikov/.cache/ms-playwright/chromium-1208/chrome-linux64/chrome',
        ].find(candidate => fs.existsSync(candidate));
        if (!executablePath) throw new Error('chromium_missing');
        browser = await chromium.launch({ headless: true, executablePath, proxy: proxyConfiguration() });
        const context = await browser.newContext({
            ignoreHTTPSErrors: true,
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        });
        // Do not request images, scripts, stylesheets, or third-party subresources.
        await context.route('**/*', route => route.request().isNavigationRequest() ? route.continue() : route.abort());
        phase = 'egress';
        evidence.request_count++;
        const ipResponse = await context.request.get('https://ipinfo.io/json', { timeout: 30000, maxRetries: 0 });
        const ip = await ipResponse.json();
        evidence.egress = { status: ipResponse.status(), country: ip.country ?? null, ip_sha256: hash(String(ip.ip ?? '')) };
        if (ipResponse.status() !== 200 || ip.country !== 'RU') throw new Error('egress_not_ru');

        await new Promise(resolve => setTimeout(resolve, 1600));
        const filter = {
            fz44: 'on', pc: 'on', okpd2Ids: '8873921', okpd2IdsWithNested: 'on',
            publishDateFrom: '01.01.2025', publishDateTo: '31.01.2025',
            priceFromGeneral: '0', priceToGeneral: '1000000000000',
            sortBy: 'PUBLISH_DATE', sortDirection: 'true',
        };
        evidence.query_params = filter;
        phase = 'ssr_listing';
        evidence.request_count++;
        const page = await context.newPage();
        const listingResponse = await page.goto(`https://zakupki.gov.ru/epz/order/extendedsearch/results.html?${new URLSearchParams({ ...filter, recordsPerPage: '_10', pageNumber: '1' })}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        const html = await page.content();
        const count = html.match(/downloadCsv\('([^']*)',\s*'(\d+)'\)/);
        evidence.ssr = {
            status: listingResponse?.status() ?? null,
            content_type: listingResponse?.headers()['content-type'] ?? null,
            bytes_utf8: Buffer.byteLength(html), sha256: hash(html),
            total_count: count ? Number(count[2]) : null,
            download_csv_marker_found: Boolean(count),
        };
        if (listingResponse?.status() !== 200 || !count) throw new Error('ssr_count_unverified');

        await new Promise(resolve => setTimeout(resolve, 1600));
        phase = 'first_csv_block';
        evidence.request_count++;
        const columns = Object.fromEntries([
            'registryNumberCsv', 'publishDateCsv', 'lastDateChangeCsv', 'stepOrderPlacementCsv',
            'methodOrderPurchaseCsv', 'nameOrderCsv', 'purchaseNumbersCsv', 'maxContractPriceCsv',
            'currencyCodeCsv', 'startDateRequestCsv', 'endDateRequestCsv', 'customerNameCsv',
        ].map(key => [key, 'on']));
        const csvResponse = await context.request.get(`https://zakupki.gov.ru/epz/order/orderCsvSettings/download.html?${new URLSearchParams({ ...filter, ...columns, from: '1', to: '500' })}`, { timeout: 30000, maxRetries: 0 });
        const bytes = await csvResponse.body();
        const decoded = new TextDecoder('windows-1251').decode(bytes);
        const rows = csvRows(decoded);
        const header = rows.shift() ?? [];
        const required = ['Реестровый номер закупки', 'Дата размещения', 'Дата обновления'];
        evidence.csv = {
            status: csvResponse.status(), content_type: csvResponse.headers()['content-type'] ?? null,
            encoding: 'windows-1251', raw_bytes: bytes.length, sha256: hash(bytes),
            headers: header, data_record_count: rows.length,
            invalid_width_count: rows.filter(row => row.length !== header.length).length,
            multiline_record_count: rows.filter(row => row.some(value => /[\r\n]/.test(value))).length,
            required_headers_present: required.every(name => header.includes(name)),
        };
        if (csvResponse.status() !== 200 || !evidence.csv.required_headers_present || evidence.csv.invalid_width_count) throw new Error('csv_unverified');
        evidence.status = 'verified';
    } catch (error) {
        evidence.failure = {
            phase,
            category: error?.name === 'TimeoutError' ? 'request_timeout'
                : error instanceof Error && /^[a-z_]+$/.test(error.message) ? error.message : 'browser_or_network_failure',
        };
    } finally {
        if (browser) await browser.close().catch(() => {});
        evidence.finished_at_utc = new Date().toISOString();
        fs.mkdirSync(path.dirname(output), { recursive: true });
        fs.writeFileSync(output, `${JSON.stringify(evidence, null, 2)}\n`);
        process.stdout.write(`${JSON.stringify({ status: evidence.status, request_count: evidence.request_count, failure: evidence.failure ?? null, ssr_total_count: evidence.ssr?.total_count ?? null, csv_record_count: evidence.csv?.data_record_count ?? null })}\n`);
        process.exitCode = evidence.status === 'verified' ? 0 : 1;
    }
})();
