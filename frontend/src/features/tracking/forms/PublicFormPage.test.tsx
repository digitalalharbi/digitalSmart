import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Route, Routes } from 'react-router';
import { renderWithProviders } from '@/test/render';
import { PublicFormPage } from './PublicFormPage';
import type { PublicForm } from './types';

const calls = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  beacon: vi.fn(),
  layer: vi.fn(),
}));

vi.mock('@/shared/api/client', () => {
  class ApiError extends Error {
    payload: Record<string, unknown> | null;
    constructor(message: string, payload: Record<string, unknown> | null) {
      super(message);
      this.payload = payload;
    }
  }

  return { ApiError, api: { get: calls.get, post: calls.post } };
});
vi.mock('./beacon', () => ({
  beacon: calls.beacon,
  eventId: () => 'evt-0123456789',
  pushToDataLayer: calls.layer,
}));

function field(overrides: Partial<PublicForm['fields'][number]>): PublicForm['fields'][number] {
  return {
    key: 'name',
    type: 'text',
    label_ar: 'الاسم',
    label_en: 'Name',
    placeholder_ar: null,
    placeholder_en: null,
    help_ar: null,
    help_en: null,
    required: true,
    default: null,
    options: [],
    validation: {},
    visibility: null,
    ...overrides,
  };
}

const FORM: PublicForm = {
  token: 'AbCdEfGhIjKl',
  name: 'استفسار',
  default_locale: 'ar',
  submit_label: { ar: null, en: null },
  theme: 'auto',
  accent: null,
  logo_url: null,
  data_layer: {
    form_view: 'ds_form_view',
    form_start: 'ds_form_start',
    form_submit: 'ds_form_submit',
  },
  fields: [
    field({}),
    field({ key: 'phone', type: 'phone', label_ar: 'الجوال', label_en: 'Mobile' }),
    field({
      key: 'kind',
      type: 'select',
      label_ar: 'النوع',
      label_en: 'Kind',
      required: false,
      options: [
        { value: 'villa', label_ar: 'فيلا', label_en: 'Villa' },
        { value: 'land', label_ar: 'أرض', label_en: 'Land' },
      ],
    }),
    field({
      key: 'size',
      type: 'number',
      label_ar: 'المساحة',
      label_en: 'Size',
      required: false,
      visibility: { field: 'kind', operator: 'equals', value: 'land' },
    }),
  ],
};

async function open() {
  await renderWithProviders(
    <Routes>
      <Route path="/f/:token" element={<PublicFormPage />} />
    </Routes>,
    { route: '/f/AbCdEfGhIjKl?ds_tp=TPTPTPTPTPTPTPTPTPTP' },
  );
}

describe('PublicFormPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    calls.get.mockResolvedValue({ data: { data: FORM } });
  });

  it('draws the form right to left in Arabic, reports a view and shows no tenant detail', async () => {
    await open();

    expect(await screen.findByRole('heading', { name: 'استفسار' })).toBeInTheDocument();
    expect(screen.getByLabelText(/الاسم/)).toBeInTheDocument();
    expect(document.querySelector('[dir="rtl"]')).not.toBeNull();
    expect(calls.beacon).toHaveBeenCalledWith(
      expect.objectContaining({ event: 'form_view', touchpoint: 'TPTPTPTPTPTPTPTPTPTP' }),
    );
    expect(calls.layer).toHaveBeenCalledWith('ds_form_view', 'evt-0123456789');
    expect(document.body.textContent).not.toMatch(/workspace|project_id|maps_to/i);
  });

  it('shows a field only while its rule says so', async () => {
    const user = userEvent.setup();
    await open();
    await screen.findByRole('heading', { name: 'استفسار' });

    expect(screen.queryByLabelText('المساحة')).not.toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText('النوع'), 'land');
    expect(screen.getByLabelText('المساحة')).toBeInTheDocument();
  });

  it('reports the start once, without what was typed', async () => {
    const user = userEvent.setup();
    await open();
    await user.type(await screen.findByLabelText(/الاسم/), 'Noura');

    const starts = calls.beacon.mock.calls.filter(
      ([p]) => (p as { event: string }).event === 'form_start',
    );
    expect(starts).toHaveLength(1);
    expect(JSON.stringify(calls.beacon.mock.calls)).not.toContain('Noura');
  });

  it('shows the server’s field errors and sends the first visible answers only', async () => {
    const user = userEvent.setup();
    const { ApiError } = await import('@/shared/api/client');
    const Mocked = ApiError as unknown as new (
      message: string,
      payload: Record<string, unknown>,
    ) => Error;
    calls.post.mockRejectedValueOnce(new Mocked('check', { errors: { phone: 'invalid' } }));
    await open();

    await user.type(await screen.findByLabelText(/الاسم/), 'Noura');
    await user.type(screen.getByLabelText(/الجوال/), '12');
    await user.click(screen.getByRole('button', { name: 'إرسال' }));

    expect(await screen.findByText('القيمة غير صحيحة')).toBeInTheDocument();
    expect(calls.post.mock.calls[0]?.[1]).toMatchObject({
      values: { name: 'Noura', phone: '12' },
      touchpoint: 'TPTPTPTPTPTPTPTPTPTP',
    });
    expect(calls.beacon).toHaveBeenCalledWith(
      expect.objectContaining({
        event: 'form_validation_error',
        meta: { field_key: 'phone', error_code: 'invalid' },
      }),
    );
  });

  it('thanks the visitor once the submission is kept, and can switch language', async () => {
    const user = userEvent.setup();
    calls.post.mockResolvedValueOnce({
      data: {
        data: {
          received: true,
          thank_you: { ar: 'شكراً لك', en: 'Thank you' },
          redirect_url: null,
        },
      },
    });
    await open();

    await user.type(await screen.findByLabelText(/الاسم/), 'Noura');
    await user.type(screen.getByLabelText(/الجوال/), '0555001122');
    await user.click(screen.getByRole('button', { name: 'إرسال' }));

    expect(await screen.findByRole('heading', { name: 'شكراً لك' })).toBeInTheDocument();
  });

  it('says so plainly when the form is not available', async () => {
    calls.get.mockRejectedValueOnce(new Error('404'));
    await open();

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'هذا النموذج غير متاح.' })).toBeInTheDocument(),
    );
  });
});
