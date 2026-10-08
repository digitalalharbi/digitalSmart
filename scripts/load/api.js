/*
 * k6 load profile for the read paths a client's dashboard actually hits.
 *
 * What this measures: the API under concurrent authenticated reads, which is the shape of real traffic — a
 * handful of people with a dashboard open, each page issuing several analytics calls at once. It does **not**
 * measure the provider adapters (they are rate-limited by the providers and run on the queue) or the write
 * paths (one approval at a time is not a load question).
 *
 * Thresholds are the point. A run with no threshold is a number somebody quotes later without the conditions
 * attached, and "the API does 400 requests a second" means nothing without saying on what, at what percentile
 * and with what error rate.
 *
 * Usage:  k6 run -e BASE=http://127.0.0.1:8001 -e EMAIL=... -e PASSWORD=... scripts/load/api.js
 *         scripts/load.sh            # which arranges all of the above
 */
import http from 'k6/http';
import { check, group, sleep } from 'k6';

const BASE = __ENV.BASE || 'http://127.0.0.1:8001';
const EMAIL = __ENV.EMAIL || 'owner@demo.digitalsmart.test';
const PASSWORD = __ENV.PASSWORD || 'password';

export const options = {
  scenarios: {
    /*
     * Ten concurrent readers for a minute, after a ramp. Not a thousand: this product serves agencies, where a
     * workspace is a dozen people and the realistic peak is everybody opening Monday's dashboard at once.
     * Benchmarking a load the product will never see tunes it for the wrong thing.
     */
    dashboard_readers: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '15s', target: 10 },
        { duration: '60s', target: 10 },
        { duration: '10s', target: 0 },
      ],
    },
  },
  thresholds: {
    /*
     * Errors first. A fast failure is not a pass, and a run that got quicker because a tenth of the requests
     * started returning 500 would otherwise look like an improvement.
     */
    http_req_failed: ['rate<0.01'],
    /* A dashboard read that takes longer than this reads as broken to the person waiting for it. */
    'http_req_duration{kind:analytics}': ['p(95)<1500'],
    /* Health is probed by a load balancer and must stay cheap even while the rest is busy. */
    'http_req_duration{kind:health}': ['p(95)<300'],
  },
};

/** Sign in once per virtual user, the way the SPA does: CSRF cookie, then the session. */
export function setup() {
  const csrf = http.get(`${BASE}/sanctum/csrf-cookie`);

  check(csrf, { 'csrf cookie issued': (response) => response.status === 204 });

  const token = (csrf.cookies['XSRF-TOKEN'] || [{}])[0].value;

  const login = http.post(
    `${BASE}/api/auth/login`,
    JSON.stringify({ email: EMAIL, password: PASSWORD }),
    {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(token || ''),
        Origin: __ENV.ORIGIN || 'http://127.0.0.1:5173',
      },
    },
  );

  check(login, { 'signed in': (response) => response.status === 200 });

  return { cookies: login.cookies };
}

export default function (data) {
  const jar = http.cookieJar();

  for (const [name, values] of Object.entries(data.cookies || {})) {
    jar.set(BASE, name, (values[0] || {}).value || '');
  }

  const headers = { Accept: 'application/json' };

  group('health', () => {
    const response = http.get(`${BASE}/api/health`, { headers, tags: { kind: 'health' } });

    check(response, { 'health answers': (r) => r.status === 200 || r.status === 503 });
  });

  group('analytics', () => {
    /*
     * The calls one dashboard paint makes, in the order it makes them. Issued as a batch because that is what
     * the browser does — measuring them one after another would report a latency no user ever experiences.
     */
    const analytics = 'preset=30d&compare=previous_period';
    const responses = http.batch([
      /* One endpoint serves the scorecard, the chart and the table — they have to agree, so they are one query. */
      ['GET', `${BASE}/api/analytics?${analytics}`, null, { headers, tags: { kind: 'analytics' } }],
      ['GET', `${BASE}/api/analytics?${analytics}&dimension=campaign&limit=25`, null, { headers, tags: { kind: 'analytics' } }],
      ['GET', `${BASE}/api/analytics/catalogue`, null, { headers, tags: { kind: 'analytics' } }],
      ['GET', `${BASE}/api/clients?per_page=25`, null, { headers, tags: { kind: 'analytics' } }],
    ]);

    for (const response of responses) {
      check(response, { 'read succeeded': (r) => r.status === 200 });
    }
  });

  /* A person reads what they asked for before asking for more. A tight loop is a benchmark, not a user. */
  sleep(2);
}
