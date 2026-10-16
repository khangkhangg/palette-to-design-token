type Listener = (changes: Record<string, { oldValue?: unknown; newValue?: unknown }>, area: string) => void;

export interface FakeChrome {
  storage: {
    local: {
      get(key: string): Promise<Record<string, unknown>>;
      set(items: Record<string, unknown>): Promise<void>;
    };
    onChanged: { addListener(l: Listener): void; removeListener(l: Listener): void };
  };
  scripting: { executeScript: (injection: unknown) => Promise<{ result?: unknown }[]> };
  tabs: { query: (q: unknown) => Promise<{ id?: number }[]> };
}

export interface FakeOptions {
  executeScript?: (injection: any) => Promise<{ result?: unknown }[]>;
  tabs?: { id?: number }[];
}

export function installFakeChrome(opts: FakeOptions = {}): { chrome: FakeChrome; data: Record<string, unknown> } {
  const data: Record<string, unknown> = {};
  const listeners = new Set<Listener>();
  const chrome: FakeChrome = {
    storage: {
      local: {
        async get(key) { return key in data ? { [key]: structuredClone(data[key]) } : {}; },
        async set(items) {
          const changes: Record<string, { oldValue?: unknown; newValue?: unknown }> = {};
          for (const [k, v] of Object.entries(items)) {
            changes[k] = { oldValue: data[k], newValue: structuredClone(v) };
            data[k] = structuredClone(v);
          }
          listeners.forEach((l) => l(changes, 'local'));
        },
      },
      onChanged: {
        addListener: (l) => { listeners.add(l); },
        removeListener: (l) => { listeners.delete(l); },
      },
    },
    scripting: { executeScript: opts.executeScript ?? (async () => [{}]) },
    tabs: { query: async () => opts.tabs ?? [] },
  };
  (globalThis as any).chrome = chrome;
  return { chrome, data };
}

export function uninstallFakeChrome(): void {
  delete (globalThis as any).chrome;
}
