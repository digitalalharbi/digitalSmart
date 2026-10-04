/// <reference types="vite/client" />

/** Build-time configuration. Every entry is optional: `env.ts` validates and defaults. */
interface ImportMetaEnv {
  readonly VITE_APP_NAME?: string;
  readonly VITE_API_BASE_URL?: string;
  readonly VITE_DEV_API_PROXY?: string;
  readonly VITE_REVERB_APP_KEY?: string;
  readonly VITE_REVERB_HOST?: string;
  readonly VITE_REVERB_PORT?: string;
  readonly VITE_REVERB_SCHEME?: string;
  readonly VITE_SHOW_DEMO_DATA?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
