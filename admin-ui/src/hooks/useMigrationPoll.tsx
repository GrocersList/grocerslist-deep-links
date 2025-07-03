import { useState, useEffect, useCallback } from 'preact/hooks';
import { useSetupContext } from './useSetupContext';
import type { MigrationStatus } from '../api/IGrocersListApi';

export const useMigrationPoll = (pollInterval = 5000) => {
  const { api } = useSetupContext();
  const [migrationStatus, setMigrationStatus] = useState<MigrationStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const [isRunning, setIsRunning] = useState(false);

  const fetchMigrationStatus = useCallback(async () => {
    try {
      const status = await api.getMigrationStatus();
      setMigrationStatus(status);

      // If migration is complete, stop polling
      if (status.isComplete) {
        setIsRunning(false);
      }

      setError(null);
    } catch (err) {
      console.error('Error fetching migration status:', err);
      setError(err instanceof Error ? err : new Error('Unknown error'));
    } finally {
      setLoading(false);
    }
  }, [api]);

  // Initial fetch
  useEffect(() => {
    fetchMigrationStatus();
  }, [fetchMigrationStatus]);

  // Polling logic
  useEffect(() => {
    // Don't poll if we're not running
    if (!isRunning) {
      return;
    }

    const interval = setInterval(fetchMigrationStatus, pollInterval);

    return () => clearInterval(interval);
  }, [isRunning, fetchMigrationStatus, pollInterval]);

  // Function to trigger migration
  const triggerMigration = useCallback(async () => {
    setLoading(true);
    setIsRunning(true);
    try {
      await api.triggerMigrate();
      // Immediately fetch the updated status
      await fetchMigrationStatus();
    } catch (err) {
      console.error('Error triggering migration:', err);
      setError(err instanceof Error ? err : new Error('Unknown error'));
      setIsRunning(false);
      setLoading(false);
    }
  }, [api, fetchMigrationStatus]);

  return {
    migrationStatus,
    loading,
    error,
    isRunning,
    triggerMigration,
    refreshMigrationStatus: fetchMigrationStatus
  };
};
