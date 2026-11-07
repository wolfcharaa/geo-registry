import http from 'k6/http';
import {check} from 'k6';
import {Rate} from 'k6/metrics';
import {addressSearchWorkload as workload} from './address-search-workload.js';

const baseUrl = (__ENV.ADDRESS_LOAD_BASE_URL || 'http://host.docker.internal:8080').replace(/\/$/, '');
const contract = enumEnvironment('ADDRESS_LOAD_CONTRACT', 'target', ['target', 'legacy']);
const variant = __ENV.ADDRESS_LOAD_VARIANT || 'unspecified';
const virtualUsers = integerEnvironment('ADDRESS_LOAD_VUS', 16, 1, 1024);
const duration = durationEnvironment('ADDRESS_LOAD_DURATION', '30s');
const maxP95Milliseconds = numberEnvironment('ADDRESS_LOAD_MAX_P95_MS', 750, 0.001);
const maxP99Milliseconds = numberEnvironment('ADDRESS_LOAD_MAX_P99_MS', 1500, 0.001);

const contractFailures = new Rate('address_search_contract_failed');
const expectedMatchFailures = new Rate('address_search_expected_match_failed');
validateWorkload(workload);

const thresholds = {
    http_req_failed: ['rate==0'],
    checks: ['rate==1'],
    address_search_contract_failed: ['rate==0'],
    http_req_duration: [
        `p(95)<${maxP95Milliseconds}`,
        `p(99)<${maxP99Milliseconds}`,
    ],
    'http_reqs{region_code:50}': ['count>0'],
    'http_reqs{region_code:77}': ['count>0'],
    'http_reqs{query_kind:exact}': ['count>0'],
    'http_reqs{query_kind:alias}': ['count>0'],
    'http_reqs{query_kind:fuzzy}': ['count>0'],
    'http_req_duration{region_code:50}': [`p(95)<${maxP95Milliseconds}`],
    'http_req_duration{region_code:77}': [`p(95)<${maxP95Milliseconds}`],
    'http_req_duration{query_kind:exact}': [`p(95)<${maxP95Milliseconds}`],
    'http_req_duration{query_kind:alias}': [`p(95)<${maxP95Milliseconds}`],
    'http_req_duration{query_kind:fuzzy}': [`p(95)<${maxP95Milliseconds}`],
};
if (contract === 'target') {
    thresholds.address_search_expected_match_failed = ['rate==0'];
}
for (const kind of ['exact', 'alias', 'fuzzy']) {
    thresholds[`address_search_expected_match_failed{query_kind:${kind}}`] = [
        contract === 'target' ? 'rate==0' : 'rate<=1',
    ];
}

export const options = {
    discardResponseBodies: false,
    scenarios: {
        address_search: {
            executor: 'constant-vus',
            vus: virtualUsers,
            duration,
            gracefulStop: '10s',
            tags: {backend_variant: variant, contract},
        },
    },
    thresholds,
    userAgent: 'geo-registry-address-search-load/1.0',
};

export default function () {
    const workloadIndex = ((__VU - 1) + __ITER) % workload.length;
    const sample = workload[workloadIndex];
    const tags = {
        backend_variant: variant,
        contract,
        endpoint: 'address_search',
        name: 'GET /api/addresses/search',
        query_kind: sample.kind,
        region_code: String(sample.regionCode),
        workload_case: String(workloadIndex + 1),
    };
    const url = `${baseUrl}/api/addresses/search?${queryString(sample)}`;
    const response = http.get(url, {
        redirects: 0,
        tags,
        timeout: __ENV.ADDRESS_LOAD_TIMEOUT || '10s',
    });
    const payload = jsonObject(response);
    const suggestions = Array.isArray(payload?.suggestions) ? payload.suggestions : null;
    const expectedMatch = suggestions?.some(
        suggestion => Number(suggestion?.data?.object_id) === sample.expectedObjectId,
    ) === true;
    const valid = response.status === 200
        && payload?.success === true
        && payload?.meta?.endpoint === 'search'
        && (contract === 'legacy' || payload?.query?.region_code === sample.regionCode)
        && payload?.query?.hierarchy === 'adm'
        && payload?.query?.profile === 'search'
        && suggestions !== null
        && suggestions.length > 0
        && payload?.count === suggestions.length;

    contractFailures.add(!valid, tags);
    expectedMatchFailures.add(!expectedMatch, tags);
    check(response, {
        'address search contract is valid': () => valid,
    }, tags);
}

function queryString(sample) {
    const parameters = [
        `q=${encodeURIComponent(sample.query)}`,
        'hier=adm',
        'limit=10',
    ];
    if (contract === 'target') {
        parameters.push(`region_code=${sample.regionCode}`);
    }

    return parameters.join('&');
}

function jsonObject(response) {
    try {
        const payload = response.json();

        return payload !== null && typeof payload === 'object' && !Array.isArray(payload) ? payload : null;
    } catch (_error) {
        return null;
    }
}

function integerEnvironment(name, fallback, minimum, maximum) {
    const raw = __ENV[name];
    if (raw === undefined || raw === '') {
        return fallback;
    }

    const value = Number(raw);
    if (!Number.isInteger(value) || value < minimum || value > maximum) {
        throw new Error(`${name} must be an integer between ${minimum} and ${maximum}.`);
    }

    return value;
}

function numberEnvironment(name, fallback, minimum) {
    const raw = __ENV[name];
    if (raw === undefined || raw === '') {
        return fallback;
    }

    const value = Number(raw);
    if (!Number.isFinite(value) || value < minimum) {
        throw new Error(`${name} must be a number greater than or equal to ${minimum}.`);
    }

    return value;
}

function durationEnvironment(name, fallback) {
    const value = __ENV[name] || fallback;
    if (!/^\d+(ms|s|m|h)$/.test(value) || value === '0ms' || value === '0s' || value === '0m' || value === '0h') {
        throw new Error(`${name} must be a positive k6 duration such as 30s or 2m.`);
    }

    return value;
}

function enumEnvironment(name, fallback, allowed) {
    const value = __ENV[name] || fallback;
    if (!allowed.includes(value)) {
        throw new Error(`${name} must be one of: ${allowed.join(', ')}.`);
    }

    return value;
}

function validateWorkload(samples) {
    const kinds = {exact: 0, alias: 0, fuzzy: 0};
    const queries = new Set();
    for (const sample of samples) {
        if (!Object.hasOwn(kinds, sample.kind)) {
            throw new Error(`Unknown address-search workload kind: ${sample.kind}.`);
        }
        if (![50, 77].includes(sample.regionCode)) {
            throw new Error(`Unsupported address-search workload region: ${sample.regionCode}.`);
        }
        if (!Number.isInteger(sample.expectedObjectId)) {
            throw new Error(`Invalid expected object ID for query: ${sample.query}.`);
        }
        if (queries.has(sample.query)) {
            throw new Error(`Duplicate address-search workload query: ${sample.query}.`);
        }

        queries.add(sample.query);
        kinds[sample.kind] += 1;
    }

    if (samples.length !== 30 || Object.values(kinds).some(count => count !== 10)) {
        throw new Error('Address-search workload must contain 30 unique cases: 10 exact, 10 alias and 10 fuzzy.');
    }
}
