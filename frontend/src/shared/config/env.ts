/**
 * Typed, validated access to build-time configuration.
 * Fail loudly at startup rather than producing undefined-driven bugs later.
 */
type AppEnv = {
  apiBaseUrl: string;
  reverb: {
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
  } | null;
  appName: string;
  isDemoDataVisible: boolean;
};

function readString(value: string | undefined, fallback: string): string {
  const trimmed = value?.trim();
  return trimmed && trimmed.length > 0 ? trimmed : fallback;
}

function readReverb(): AppEnv['reverb'] {
  const key = import.meta.env.VITE_REVERB_APP_KEY?.trim();
  if (!key) {
    // Realtime is optional in local/dev and in tests; the UI degrades to polling.
    return null;
  }

  const scheme = import.meta.env.VITE_REVERB_SCHEME === 'https' ? 'https' : 'http';
  const port = Number.parseInt(readString(import.meta.env.VITE_REVERB_PORT, '8080'), 10);

  return {
    key,
    host: readString(import.meta.env.VITE_REVERB_HOST, '127.0.0.1'),
    port: Number.isFinite(port) ? port : 8080,
    scheme,
  };
}

export const env: AppEnv = {
  apiBaseUrl: readString(import.meta.env.VITE_API_BASE_URL, '/api'),
  reverb: readReverb(),
  appName: readString(import.meta.env.VITE_APP_NAME, 'digitalSmart'),
  isDemoDataVisible: import.meta.env.VITE_SHOW_DEMO_DATA === 'true',
};
