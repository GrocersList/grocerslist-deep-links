import { useState } from 'react';

import { Sync } from '@mui/icons-material';
import { Stack, Typography } from '@mui/material';

import { Section } from './Section';
import { ToggleInput } from './ToggleInput';
import { useSetupContext } from '@/hooks/useSetupContext';

// The one-time send of the newest posts, as ContentPublisher::backfillReport()
// describes it: `at` is when it reached `state`, `retryAt` 0 once it gave up.
export interface IInitialSync {
  state: 'scheduled' | 'running' | 'done' | 'failed';
  at: number;
  posts: number;
  error: string;
  attempts: number;
  retryAt: number;
}

const formatTime = (seconds: number) =>
  new Date(seconds * 1000).toLocaleString();

// A retry keeps the last try's reason while it runs; only the lines that say
// it failed are red.
const initialSyncFailed = (sync: IInitialSync): boolean =>
  sync.state === 'failed' || (sync.state === 'scheduled' && sync.error !== '');

const initialSyncText = (sync: IInitialSync): string => {
  const reason = sync.error.replace(/\.$/, '');

  switch (sync.state) {
    case 'done':
      return `Initial sync: ${sync.posts} ${sync.posts === 1 ? 'post' : 'posts'} on ${formatTime(sync.at)}`;
    case 'running':
      return `Initial sync: running since ${formatTime(sync.at)}`;
    case 'scheduled':
      return reason
        ? `Initial sync failed: ${reason}. Trying again, waiting for WP-Cron since ${formatTime(sync.at)}`
        : `Initial sync: waiting for WP-Cron since ${formatTime(sync.at)}`;
    case 'failed':
      return sync.retryAt
        ? `Initial sync failed on ${formatTime(sync.at)}: ${reason}. Trying again after ${formatTime(sync.retryAt)}`
        : `Initial sync failed on ${formatTime(sync.at)}: ${reason}. Gave up after ${sync.attempts} tries: turn "Send new posts to GRO" off and on to start it again`;
    default: {
      const unhandled: never = sync.state;
      return unhandled;
    }
  }
};

export const ContentSyncSection = ({
  addToast,
}: {
  addToast: (success: boolean, message: string) => void;
}) => {
  const { api } = useSetupContext();
  const contentSync = window.grocersList?.contentSync;

  const [enabled, setEnabled] = useState(contentSync?.enabled ?? true);
  const [switched, setSwitched] = useState(false);
  const [saving, setSaving] = useState(false);
  const lastSyncAt = contentSync?.lastSyncAt;
  // Nothing runs while sending is off, and turning it back on starts the
  // initial sync over: once the switch moves, the page's line is out of date.
  const initialSync = enabled && !switched ? contentSync?.initialSync : null;

  const handleSetEnabled = async (next: boolean) => {
    setSaving(true);
    try {
      await api.updateContentSync(next);
      setEnabled(next);
      setSwitched(true);
    } catch {
      addToast(false, '❌ Failed to update the Digests setting');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Section title="Digests" icon={<Sync color="primary" />}>
      <Stack spacing={1}>
        <ToggleInput
          label="Send new posts to GRO"
          description="GRO uses your newest posts to build your Digest emails."
          checked={enabled}
          onChange={handleSetEnabled}
          disabled={saving}
        />
        <Typography variant="caption" color="text.secondary">
          {lastSyncAt
            ? `Last synced ${formatTime(lastSyncAt)}`
            : 'Not synced yet'}
        </Typography>
        {initialSync && (
          <Typography
            variant="caption"
            color={initialSyncFailed(initialSync) ? 'error' : 'text.secondary'}
          >
            {initialSyncText(initialSync)}
          </Typography>
        )}
      </Stack>
    </Section>
  );
};
