import { useEffect, useState } from 'react';

import { Link } from '@mui/icons-material';
import LoadingButton from '@mui/lab/LoadingButton';
import { Alert, Box, Stack, Typography } from '@mui/material';

import { AppLinksZeroState } from './AppLinksZeroState';
import { Section } from './Section';
import { ToggleInput } from './ToggleInput';
import type { MigrationStatus } from '@/api/IGrocersListApi';
// import { useLinkCountPoll } from '@/hooks/useLinkCountPoll'; // TODO: add back
import { useSetupContext } from '@/hooks/useSetupContext';

export const DeepLinksSection = ({
  addToast,
}: {
  addToast: (success: boolean, message: string) => void;
}) => {
  const {
    api,
    autoRewriteEnabled,
    setAutoRewriteEnabled,
    useLinkstaLinks,
    setUseLinkstaLinks,
    creatorProvisioningSettings,
  } = useSetupContext();

  const { hasAppLinksAddon } = creatorProvisioningSettings?.appLinks || {};

  // ==================== STATE MANAGEMENT ====================

  // UI state
  const [serveLinkstaLinks, setServeLinkstaLinks] = useState(useLinkstaLinks);
  const [isAutoRewriteEnabled, setIsAutoRewriteEnabled] =
    useState(autoRewriteEnabled);

  // Migration state
  const [migrationStartedAt, setMigrationStartedAt] = useState<number | null>(
    null
  );
  const [migrationStatus, setMigrationStatus] =
    useState<MigrationStatus | null>(null);

  // const { linkCountInfo } = useLinkCountPoll(); // TODO: add back

  // ==================== EFFECTS ====================

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

    migrationPollingInterval = setInterval(poll, 5000);
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

  const handleSetIsAutoRewriteEnabled = async (enabled: boolean) => {
    await api.updateAutoRewrite(enabled);
    setIsAutoRewriteEnabled(enabled);
    setAutoRewriteEnabled(enabled);
  };

  const handleSetUseLinkstaLinks = async (enabled: boolean) => {
    await api.updateUseLinkstaLinks(enabled);
    setServeLinkstaLinks(enabled);
    setUseLinkstaLinks(enabled);
  };

  return (
    <Section title="Deep Link Settings" icon={<Link color="primary" />}>
      {!hasAppLinksAddon ? (
        <AppLinksZeroState />
      ) : (
        <Stack spacing={2}>
          <ToggleInput
            label="Auto-generate Grocers List deep links"
            description="Generate GrocersList deep links for all Amazon links when saving a post"
            checked={isAutoRewriteEnabled}
            onChange={handleSetIsAutoRewriteEnabled}
          />

          <ToggleInput
            label="Serve Grocers List deep links"
            description="Display GrocersList deep links to site visitors"
            checked={serveLinkstaLinks}
            onChange={handleSetUseLinkstaLinks}
          />

          <Box>
            <Stack spacing={1}>
              <Typography variant="body1">Migration</Typography>
              <Typography variant="body2" color="text.secondary">
                Scan existing posts and generate Grocers List Deep Links for all
                Amazon links.
              </Typography>

              {!!migrationStatus?.lastMigrationCompletedAt && (
                <Typography variant="caption" color="text.secondary">
                  Last migrated:{' '}
                  {migrationStatus?.lastMigrationCompletedAt
                    ? new Date(
                        migrationStatus.lastMigrationCompletedAt
                      ).toString()
                    : 'never'}
                </Typography>
              )}
            </Stack>
          </Box>

          {/* TODO: uncomment when linkCountInfo.unmappedLinks is accurate */}
          {/*{linkCountInfo &&*/}
          {/*  (needsMigration ? (*/}
          {/*    <Alert severity="info">*/}
          {/*      {linkCountInfo.unmappedLinks} out of {linkCountInfo.totalLinks}{' '}*/}
          {/*      Amazon links have not been migrated to Grocers List Deep Links*/}
          {/*    </Alert>*/}
          {/*  ) : (*/}
          {/*    <Alert severity="info">*/}
          {/*      All {linkCountInfo.totalLinks} Amazon links have been migrated*/}
          {/*      to Grocers List Deep Links*/}
          {/*    </Alert>*/}
          {/*  ))}*/}

          <LoadingButton
            variant="outlined"
            onClick={startMigration}
            loading={migrationStatus?.isRunning}
            disabled={migrationStatus?.isRunning}
          >
            Run Migration
          </LoadingButton>

          {((migrationStartedAt && migrationStatus?.isComplete) ||
            migrationStatus?.isRunning) && (
            <Alert severity={migrationStatus?.isRunning ? 'info' : 'success'}>
              {migrationStatus?.isRunning
                ? `Migrating: ${migrationStatus?.migratedPosts || 0} / ${migrationStatus?.totalPosts || 0} posts`
                : `Migration complete! Migrated ${migrationStatus?.migratedPosts || 0} posts.`}
            </Alert>
          )}
        </Stack>
      )}
    </Section>
  );
};
