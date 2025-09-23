import { useMemo, useRef, useState } from 'react';

import { Key, Visibility, VisibilityOff } from '@mui/icons-material';
import LoadingButton from '@mui/lab/LoadingButton';
import {
  Alert,
  Box,
  IconButton,
  InputAdornment,
  Stack,
  TextField,
  Typography,
} from '@mui/material';

import { Section } from './Section';
import { useSetupContext } from '@/hooks/useSetupContext';

export const ApiKeySection = ({
  addToast,
}: {
  addToast: (success: boolean, message: string) => void;
}) => {
  const { api, apiKey, setApiKey, loading: setupLoading } = useSetupContext();
  const initialApiKey = useMemo(() => apiKey, []);

  // UI state
  const [showApiKey, setShowApiKey] = useState(false);
  const [loading, setLoading] = useState(setupLoading);
  const [apiKeyError, setApiKeyError] = useState<string | null>(null);

  // Refs
  const apiKeyInputRef = useRef<HTMLInputElement>(null);

  const shakeApiKeyInput = () => {
    const input = apiKeyInputRef.current;
    if (input) {
      input.classList.remove('shake');
      void input.offsetWidth;
      input.classList.add('shake');
    }
  };

  const validateAndSaveApiKey = async (): Promise<void> => {
    if (apiKey.trim().length < 10) {
      setApiKeyError('API Key must be at least 10 characters');
      shakeApiKeyInput();
      return;
    }

    setApiKeyError(null);
    setLoading(true);

    try {
      await api.updateApiKey(apiKey);
      addToast(true, '✅ API Key Saved!');
      window.location.reload();
    } catch (error) {
      console.error('Failed to save API Key', error);
      shakeApiKeyInput();
      addToast(false, '❌ Failed to Save API Key');
    } finally {
      setLoading(false);
    }
  };

  return (
    <Section title="API Key" icon={<Key color="primary" />}>
      <Stack spacing={2}>
        <Typography variant="body2" color="text.secondary">
          You can find your API key on the{' '}
          <Box
            component="a"
            href="https://app.grocerslist.com/creator-hq/settings"
            target="_blank"
            rel="noopener noreferrer"
            sx={{ display: 'inline-flex', alignItems: 'center' }}
          >
            Grocers List settings page
          </Box>
          .
        </Typography>
        <TextField
          fullWidth
          label="API Key"
          type={showApiKey ? 'text' : 'password'}
          inputRef={apiKeyInputRef}
          value={apiKey}
          onChange={e => setApiKey(e.target.value)}
          error={Boolean(apiKeyError)}
          helperText={apiKeyError}
          InputProps={{
            endAdornment: (
              <InputAdornment position="end">
                <IconButton
                  onClick={() => setShowApiKey(!showApiKey)}
                  edge="end"
                >
                  {showApiKey ? <VisibilityOff /> : <Visibility />}
                </IconButton>
              </InputAdornment>
            ),
          }}
        />
        {/* presence of window.grocersList.settings indicates successful use of api key to GET /creator-settings */}
        {apiKey === initialApiKey && !window.grocersList?.settings && (
          <Alert severity="warning">Invalid API key</Alert>
        )}
        <LoadingButton
          variant="contained"
          onClick={validateAndSaveApiKey}
          loading={loading}
          fullWidth
          size="small"
          disabled={!apiKey || apiKey === initialApiKey}
        >
          Save and Validate
        </LoadingButton>
      </Stack>
    </Section>
  );
};
