import {useCallback, useEffect, useState} from 'preact/hooks';
import {Stack, Typography, CircularProgress, Snackbar, Alert, Box, LinearProgress} from '@mui/material';
import {LoadingButton} from '@mui/lab';
import {useSetupContext} from '../hooks/useSetupContext';
import {useLinkCountPoll} from '../hooks/useLinkCountPoll';
import type {MigrationStatus, LinkCountInfo} from '../api/IGrocersListApi';

export const StepMigratePosts = ({onComplete}: { onComplete: () => void }) => {
  const {api} = useSetupContext();
  const [runningMigration, setRunningMigration] = useState(false);
  const [snackbarOpen, setSnackbarOpen] = useState(false);
  const [migrationStatus, setMigrationStatus] = useState<MigrationStatus | null>(null);
  const [, setLoadingStatus] = useState(false);
  const [autoRecountAttempted, setAutoRecountAttempted] = useState(false);

  // Use the new hook for link count polling
  const {
    linkCountInfo,
    loading: loadingLinkCountInfo,
    triggerRecount: runRecount
  } = useLinkCountPoll();

  // Determine if recount is running based on linkCountInfo
  const runningRecount = linkCountInfo?.isRunning || false;

  const fetchMigrationStatus = useCallback(async () => {
    setLoadingStatus(true);
    try {
      const status = await api.getMigrationStatus();
      setMigrationStatus(status);
      if (status.isComplete) {
        setRunningMigration(false);
        onComplete();
      }
    } finally {
      setLoadingStatus(false);
    }
  }, [api, onComplete]);

  // Auto-trigger recount on first load
  useEffect(() => {
    if (!autoRecountAttempted && !runningRecount) {
      setAutoRecountAttempted(true);
      runRecount();
    }
  }, [autoRecountAttempted, runningRecount, runRecount]);

  // Poll migration only if running, stop once complete
  useEffect(() => {
    if (!runningMigration) return;

    const poll = async () => {
      await fetchMigrationStatus();
    };

    const interval = setInterval(poll, 5000);

    return () => clearInterval(interval);
  }, [runningMigration, fetchMigrationStatus]);

  const runMigration = useCallback(async () => {
    setRunningMigration(true);
    setSnackbarOpen(true);
    try {
      await api.triggerMigrate();
    } catch (err) {
      console.error('Trigger migration failed', err);
      setRunningMigration(false);
    }
  }, [api]);

  return (
    <Stack spacing={3}>
      <Typography variant="h6">Step 2: Automatically Convert Amazon Links on your Blog Posts into Deep
        Links</Typography>

      {loadingLinkCountInfo ? (
        <CircularProgress/>
      ) : linkCountInfo ? (
        <LinkCountStatusCard
          linkCountInfo={linkCountInfo}
          runningRecount={runningRecount}
          runRecount={runRecount}
        />
      ) : null}

      {runningMigration && migrationStatus && (
        <MigrationStatusCard migrationStatus={migrationStatus}/>
      )}

      <RunMigrationButton
        runMigration={runMigration}
        runningMigration={runningMigration}
        loadingLinkCountInfo={loadingLinkCountInfo}
        linkCountInfo={linkCountInfo}
      />
      <Snackbar
        open={snackbarOpen}
        autoHideDuration={3000}
        onClose={() => setSnackbarOpen(false)}
        anchorOrigin={{vertical: 'bottom', horizontal: 'center'}}
      >
        <Alert
          severity={
            linkCountInfo?.isComplete && !runningRecount
              ? "success"
              : runningRecount
                ? "info"
                : migrationStatus?.isComplete
                  ? "success"
                  : "info"
          }
          sx={{width: '100%'}}
        >
          {runningRecount && linkCountInfo
            ? `${linkCountInfo.processedPosts} / ${linkCountInfo.totalPosts} posts processed`
            : linkCountInfo?.isComplete && !runningRecount
              ? `Link Count Complete: ${linkCountInfo.postsWithLinks} posts with ${linkCountInfo.totalLinks} links`
              : migrationStatus?.isComplete
                ? "Migration completed successfully!"
                : migrationStatus
                  ? `Migration in progress: ${migrationStatus.processed} of ${migrationStatus.total} posts processed`
                  : runningRecount
                    ? "Loading link count..."
                    : "Starting migration..."}
        </Alert>
      </Snackbar>
    </Stack>
  );
};

const LinkCountStatusCard = (
  {
    linkCountInfo,
    runningRecount,
    runRecount,
  }: {
    linkCountInfo: LinkCountInfo;
    runningRecount: boolean;
    runRecount: () => void;
  }) => {
  // Calculate progress percentage
  const progressPercent = linkCountInfo?.totalPosts > 0
    ? (linkCountInfo.processedPosts / linkCountInfo.totalPosts) * 100
    : 0;

  // Determine the current state
  const isLoading = !linkCountInfo;
  const isRunning = linkCountInfo?.isRunning;
  const isComplete = linkCountInfo?.isComplete;

  return (
    <Box sx={{p: 2, border: '1px solid #eee', borderRadius: 1}}>
      <Typography variant="subtitle1" gutterBottom>
        Here’s what we found:
      </Typography>

      {isLoading && (
        <Box sx={{display: 'flex', justifyContent: 'center', my: 2}}>
          <CircularProgress/>
          <Typography variant="body2" sx={{ml: 2}}>
            Loading link count...
          </Typography>
        </Box>
      )}

      {isRunning && linkCountInfo && (
        <>
          <Box sx={{width: '100%', mb: 2}}>
            <Box sx={{display: 'flex', justifyContent: 'space-between', mb: 1}}>
              <Typography variant="body2">
                {linkCountInfo.processedPosts} / {linkCountInfo.totalPosts} posts processed
              </Typography>
            </Box>
            <LinearProgress
              variant="determinate"
              value={progressPercent}
            />
          </Box>
        </>
      )}

      {isComplete && linkCountInfo && (
        <Box sx={{mb: 2}}>
          <Typography sx={{color: 'success.main', display: 'flex', alignItems: 'center', mb: 1}}>
            <span style={{marginRight: '8px'}}>✅</span>
            {linkCountInfo.postsWithLinks} posts with {linkCountInfo.totalLinks} links
          </Typography>

          <Typography variant="body2" color="text.secondary">
            Last counted: {new Date(linkCountInfo.lastCount * 1000).toLocaleString()}
          </Typography>
        </Box>
      )}

      <LoadingButton
        onClick={runRecount}
        loading={runningRecount}
        variant="outlined"
        size="small"
        disabled={isRunning}
        sx={{mt: 2}}
      >
        Update Link Stats
      </LoadingButton>
    </Box>
  );
};

const MigrationStatusCard = (
  {
    migrationStatus,
  }: {
    migrationStatus: MigrationStatus;
  }) => (
  <Box sx={{width: '100%', mb: 2}}>
    <Box sx={{display: 'flex', justifyContent: 'space-between', mb: 1}}>
      <Typography variant="body2">
        Migration in progress: {migrationStatus.processed} of {migrationStatus.total} posts processed
      </Typography>
      <Typography variant="body2">
        {migrationStatus.remaining} posts remaining
      </Typography>
    </Box>
    <LinearProgress
      variant="determinate"
      value={migrationStatus.total > 0 ? (migrationStatus.processed / migrationStatus.total) * 100 : 0}
    />
  </Box>
);

const RunMigrationButton = (
  {
    runMigration,
    runningMigration,
    loadingLinkCountInfo,
    linkCountInfo,
  }: {
    runMigration: () => void;
    runningMigration: boolean;
    loadingLinkCountInfo: boolean;
    linkCountInfo: LinkCountInfo | null;
  }) => (
  <LoadingButton
    variant="contained"
    onClick={runMigration}
    loading={runningMigration}
    fullWidth
    disabled={loadingLinkCountInfo || linkCountInfo?.totalLinks === null || runningMigration}
  >
    {linkCountInfo?.totalLinks === 0 ? "Continue" : "Convert all Amazon Links to Deep Links Now!"}
  </LoadingButton>
);
