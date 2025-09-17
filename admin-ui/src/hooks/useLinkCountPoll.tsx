import { useCallback, useEffect, useState } from 'react';

import type { LinkCountInfo } from '../api/IGrocersListApi';

import { useSetupContext } from './useSetupContext';

export const useLinkCountPoll = (pollInterval = 5000) => {
  const { api } = useSetupContext();
  const [linkCountInfo, setLinkCountInfo] = useState<LinkCountInfo | null>(
    null
  );
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  const fetchLinkCountInfo = useCallback(async () => {
    try {
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

  // Polling logic
  useEffect(() => {
    // Don't poll if we're not running or if we're complete
    if (!linkCountInfo?.isRunning || linkCountInfo?.isComplete) {
      return;
    }

    const interval = setInterval(fetchLinkCountInfo, pollInterval);

    return () => clearInterval(interval);
  }, [
    linkCountInfo?.isRunning,
    linkCountInfo?.isComplete,
    fetchLinkCountInfo,
    pollInterval,
  ]);

  // Function to trigger a recount
  const triggerRecount = useCallback(async () => {
    setLoading(true);
    try {
      await api.triggerRecountLinks();
      // Immediately fetch the updated status
      await fetchLinkCountInfo();
    } catch (err) {
      console.error('Error triggering recount:', err);
      setError(err instanceof Error ? err : new Error('Unknown error'));
      setLoading(false);
    }
  }, [api, fetchLinkCountInfo]);

  return {
    linkCountInfo,
    loading,
    error,
    triggerRecount,
    refreshLinkCountInfo: fetchLinkCountInfo,
  };
};
