/** Mirrors the backend contract of GET /api/health (see docs/API.md). */
export type ServiceStatus = 'ok' | 'degraded' | 'down';

export type HealthService = {
  name: string;
  status: ServiceStatus;
  detail: string | null;
};

export type HealthReport = {
  application: string;
  version: string;
  environment: string;
  status: ServiceStatus;
  services: HealthService[];
};
