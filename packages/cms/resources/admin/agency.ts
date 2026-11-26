export type AgencyRequest = (path: string, method?: string, data?: unknown) => Promise<any>;
export type ProjectId = string | number;
export type PortalField = {
  key: string;
  label: string;
  type: "text" | "number" | "date" | "boolean" | "select";
  options: string[];
};
export type PortalPlan = {
  name: string;
  pages: { slug: string; title: string; body: string; cta: string }[];
  fields: PortalField[];
  states: string[];
  questions: string[];
};
export type PortalProject = {
  id: ProjectId;
  state: "queued" | "running" | "review" | "failed" | "applied";
  version: number;
  proposal: PortalPlan | null;
  brief?: string;
  materials?: string;
  pages: { id: number; slug: string; title: string; version: number; preview_url: string }[];
  provider: string;
  budget_cents: number;
  cost_cents: number | null;
  error: string | null;
};
export const stateLabels = {
  queued: "W kolejce",
  running: "W przygotowaniu",
  review: "Do sprawdzenia",
  failed: "Przygotowanie nie powiodło się",
  applied: "Konfiguracja aktywna",
};
