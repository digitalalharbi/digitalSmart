export type FieldType =
  | 'text'
  | 'email'
  | 'phone'
  | 'number'
  | 'textarea'
  | 'select'
  | 'radio'
  | 'checkbox'
  | 'date'
  | 'hidden'
  | 'consent';

export type FieldOption = { value: string; label_ar: string | null; label_en: string | null };

export type Visibility = {
  field: string;
  operator: 'equals' | 'not_equals' | 'in' | 'filled';
  value: string | null;
};

export type FormField = {
  key: string;
  type: FieldType;
  position?: number;
  label_ar: string | null;
  label_en: string | null;
  placeholder_ar: string | null;
  placeholder_en: string | null;
  help_ar: string | null;
  help_en: string | null;
  required: boolean;
  default: string | null;
  options: FieldOption[];
  validation: Record<string, number>;
  visibility: Visibility | null;
  maps_to?: string | null;
};

export type FormStatus = 'draft' | 'published' | 'archived';

export type FormSummary = {
  id: string;
  client: { id: string; name: string };
  project: { id: string; name: string };
  name: string;
  status: FormStatus;
  public_url_token: string;
  default_locale: 'ar' | 'en';
  creates_lead: boolean;
  submit_label_ar: string | null;
  submit_label_en: string | null;
  thank_you_ar: string | null;
  thank_you_en: string | null;
  redirect_url: string | null;
  theme: 'auto' | 'light' | 'dark';
  accent: string | null;
  logo_url: string | null;
  fields: FormField[];
  submissions: { value: number; state: 'live' } | null;
  created_at: string;
};

export type FormDraft = {
  project_id: string;
  name: string;
  default_locale: 'ar' | 'en';
  creates_lead: boolean;
  thank_you_ar?: string;
  thank_you_en?: string;
  submit_label_ar?: string;
  submit_label_en?: string;
  redirect_url?: string;
  theme: 'auto' | 'light' | 'dark';
  accent?: string;
  logo_url?: string;
  fields: FormField[];
};

export type PublicForm = {
  token: string;
  name: string;
  default_locale: 'ar' | 'en';
  submit_label: { ar: string | null; en: string | null };
  theme: 'auto' | 'light' | 'dark';
  accent: string | null;
  logo_url: string | null;
  /** Browser event => the data layer event name the project chose, for Tag Manager to listen to. */
  data_layer: Record<string, string>;
  fields: FormField[];
};

export type SubmitResult = {
  received: boolean;
  thank_you: { ar: string | null; en: string | null };
  redirect_url: string | null;
};
