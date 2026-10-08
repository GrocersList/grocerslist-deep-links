import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

import type { FormConfig } from '../form/config';

interface Loaded {
  hash: string;
  config: FormConfig | null;
}

// The selected form's config, or null for one GRO no longer has; undefined
// while it loads.
export const useFormConfig = (hash: string): FormConfig | null | undefined => {
  const [loaded, setLoaded] = useState<Loaded | null>(null);

  useEffect(() => {
    if (!hash) {
      return undefined;
    }

    let cancelled = false;

    apiFetch<FormConfig>({
      path: `/grocerslist/v1/forms/${encodeURIComponent(hash)}`,
    })
      .then(config => {
        if (!cancelled) {
          setLoaded({ hash, config });
        }
      })
      .catch(() => {
        if (!cancelled) {
          setLoaded({ hash, config: null });
        }
      });

    return () => {
      cancelled = true;
    };
  }, [hash]);

  return loaded && loaded.hash === hash ? loaded.config : undefined;
};
