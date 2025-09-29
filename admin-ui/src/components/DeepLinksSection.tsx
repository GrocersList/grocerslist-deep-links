import { useEffect, useMemo, useState } from 'react';

import { Link } from '@mui/icons-material';
import LoadingButton from '@mui/lab/LoadingButton';
import {
  Alert,
  AlertTitle,
  Box,
  LinearProgress,
  Stack,
  Typography,
} from '@mui/material';

import { AppLinksZeroState } from './AppLinksZeroState';
import { Section } from './Section';
import { ToggleInput } from './ToggleInput';
import type { MigrationStatus } from '@/api/IGrocersListApi';
import { useLinkCount } from '@/hooks/useLinkCount';
import { useSetupContext } from '@/hooks/useSetupContext';

export const DeepLinksSection = ({
  addToast,
}: {
  addToast: (success: boolean, message: string) => void;
}) => {
  const {
    api,
    useLinkstaLinks,
    setUseLinkstaLinks,
    creatorProvisioningSettings,
  } = useSetupContext();

  const { hasAppLinksAddon } = creatorProvisioningSettings?.appLinks || {};

  // ==================== STATE MANAGEMENT ====================

  // Migration state
  const [migrationStartedAt, setMigrationStartedAt] = useState<number | null>(
    null
  );
  const [migrationStatus, setMigrationStatus] =
    useState<MigrationStatus | null>(null);

  const {
    linkCountInfo,
    loading: linkCountLoading,
    fetchLinkCountInfo,
  } = useLinkCount();

  const needsMigration = useMemo(
    () => (linkCountInfo?.totalUnmappedLinks || 0) > 0,
    [linkCountInfo?.totalUnmappedLinks]
  );

  // ==================== EFFECTS ====================

  useEffect(() => {
    if (migrationStatus?.isComplete) {
      fetchLinkCountInfo();
    }
  }, [migrationStatus?.isComplete, fetchLinkCountInfo]);

  useEffect(() => {
    // Load migration status on mount
    const loadMigrationStatus = async () => {
      try {
        const status = await api.getMigrationStatus();
        setMigrationStatus(status);
      } catch (error) {
        console.error('Failed to load migration status:', error);
      }
    };

    loadMigrationStatus();
  }, [api]);

  let migrationPollingInterval: NodeJS.Timeout;

  useEffect(() => {
    if (!migrationStatus?.isRunning) {
      return () => clearInterval(migrationPollingInterval);
    }

    const poll = async () => {
      try {
        const status = await api.getMigrationStatus();
        setMigrationStatus(status);
      } catch (error) {
        console.error('Failed to poll migration status:', error);
      }
    };

    migrationPollingInterval = setInterval(poll, 2000);
    return () => clearInterval(migrationPollingInterval);
  }, [migrationStatus, api]);

  const startMigration = async () => {
    try {
      setMigrationStartedAt(Date.now());
      await api.triggerMigrate();
      addToast(true, '✅ Migration started!');
      // Fetch initial status
      const status = await api.getMigrationStatus();
      setMigrationStatus(status);
    } catch (error) {
      console.error('Failed to start migration:', error);
      addToast(false, '❌ Failed to start migration');
    }
  };

  const handleSetUseLinkstaLinks = async (enabled: boolean) => {
    await api.updateUseLinkstaLinks(enabled);
    setUseLinkstaLinks(enabled);

    if (enabled && needsMigration) {
      await startMigration();
    }
  };

  return (
    <Section title="Deep Link Settings" icon={<Link color="primary" />}>
      {!hasAppLinksAddon ? (
        <AppLinksZeroState />
      ) : (
        <Stack spacing={1}>
          <ToggleInput
            label="Enable Grocers List Deep Links"
            description="Convert Amazon links to GrocersList Deep Links"
            checked={useLinkstaLinks}
            onChange={handleSetUseLinkstaLinks}
          />

          {linkCountLoading && (
            <Alert severity="info">
              <LinearProgress />
            </Alert>
          )}

          {linkCountInfo &&
            (needsMigration ? (
              <Alert severity="warning">
                {linkCountInfo.totalUnmappedLinks} of{' '}
                {linkCountInfo.totalAmazonLinks} Amazon links need to be mapped
                to Grocers List Deep Links. Click "Run Migration" below to map
                all existing Amazon links to Grocers List Deep Links.
              </Alert>
            ) : (
              <Alert severity="info">
                All {linkCountInfo.totalAmazonLinks} Amazon links have been
                mapped to Grocers List Deep Links.
              </Alert>
            ))}

          <Box>
            {!!migrationStatus?.lastMigrationCompletedAt && (
              <Typography variant="caption" color="text.secondary">
                Last migrated:{' '}
                {migrationStatus?.lastMigrationCompletedAt
                  ? new Date(
                      migrationStatus.lastMigrationCompletedAt * 1000
                    ).toString()
                  : 'never'}
              </Typography>
            )}
          </Box>

          <LoadingButton
            variant="outlined"
            onClick={startMigration}
            loading={migrationStatus?.isRunning}
            disabled={migrationStatus?.isRunning || !needsMigration}
          >
            Run Migration
          </LoadingButton>

          {migrationStartedAt && migrationStatus?.isComplete && (
            <Alert severity="success">Migration complete!</Alert>
          )}

          {migrationStatus?.isRunning && (
            <Alert severity="info">
              <AlertTitle>Migrating...</AlertTitle>
              <LinearProgress />
            </Alert>
          )}
        </Stack>
      )}
    </Section>
  );
};
