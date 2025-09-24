import { useCallback, useEffect, useState } from 'react';

import { useSetupContext } from './useSetupContext';
import type { LinkCountInfo } from '@/api/IGrocersListApi';

export const useLinkCount = () => {
  const { api } = useSetupContext();
  const [linkCountInfo, setLinkCountInfo] = useState<LinkCountInfo | null>(
    null
  );
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  const fetchLinkCountInfo = useCallback(async () => {
    try {
      setLoading(true);
      const info = await api.getLinkCountInfo();
      setLinkCountInfo(info);
      setError(null);
    } catch (err) {
      console.error('Error fetching link count info:', err);
      setError(err instanceof Error ? err : new Error('Unknown error'));
    } finally {
      setLoading(false);
    }
  }, [api]);

  // Initial fetch
  useEffect(() => {
    fetchLinkCountInfo();
  }, [fetchLinkCountInfo]);

  return {
    linkCountInfo,
    fetchLinkCountInfo,
    loading,
    error,
  };
};
