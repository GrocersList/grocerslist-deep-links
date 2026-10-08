import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

import type { FormSummary } from '../form/config';

// Why GET /grocerslist/v1/forms has no list: FormConfigCache::listProblem().
export type Problem = 'not_connected' | 'unavailable' | 'unreachable';

export interface FormList {
  connected: boolean;
  problem: Problem | null;
  forms: FormSummary[];
}

const PROBLEMS: Problem[] = ['not_connected', 'unavailable', 'unreachable'];

const toProblem = (connected: boolean, reason: unknown): Problem | null => {
  if (connected) {
    return null;
  }

  return PROBLEMS.find(problem => problem === reason) || 'not_connected';
};

// The creator's GRO forms, for a block's picker; null while they load.
export const useGroForms = (): FormList | null => {
  const [list, setList] = useState<FormList | null>(null);

  useEffect(() => {
    let cancelled = false;

    apiFetch<{ connected?: unknown; reason?: unknown; forms?: unknown }>({
      path: '/grocerslist/v1/forms',
    })
      .then(response => {
        if (!cancelled) {
          const connected = Boolean(response.connected);
          setList({
            connected,
            problem: toProblem(connected, response.reason),
            forms: Array.isArray(response.forms)
              ? (response.forms as FormSummary[])
              : [],
          });
        }
      })
      .catch(() => {
        if (!cancelled) {
          setList({ connected: false, problem: 'unreachable', forms: [] });
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  return list;
};

type FormPick = (forms: FormSummary[]) => FormSummary | undefined;

const useDefaultForm = (
  formId: string,
  list: FormList | null,
  setAttributes: (attributes: { formId: string }) => void,
  pick: FormPick
): void => {
  useEffect(() => {
    if (formId || !list) {
      return;
    }

    const form = pick(list.forms);
    if (form) {
      setAttributes({ formId: form.hash });
    }
  }, [formId, list, setAttributes, pick]);
};

const defaultSignup: FormPick = forms =>
  forms.find(form => form.kind === 'SIGNUP' && form.isDefault);

// The creator's default content-gate form, else their first one, else their
// default signup form. (A gate saved with no form shows the default
// content-gate form, else the default signup form: FormRenderer.php.)
export const defaultGateForm: FormPick = forms =>
  forms.find(form => form.kind === 'CONTENT_GATE' && form.isDefault) ||
  forms.find(form => form.kind === 'CONTENT_GATE') ||
  defaultSignup(forms);

// A block dropped into a post starts on the creator's default signup form
// straight away; the picker changes it.
export const useDefaultSignupForm = (
  formId: string,
  list: FormList | null,
  setAttributes: (attributes: { formId: string }) => void
): void => useDefaultForm(formId, list, setAttributes, defaultSignup);

// A gate dropped into a post starts on the form made for gates.
export const useDefaultGateForm = (
  formId: string,
  list: FormList | null,
  setAttributes: (attributes: { formId: string }) => void
): void => useDefaultForm(formId, list, setAttributes, defaultGateForm);
