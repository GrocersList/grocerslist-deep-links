import { useState } from 'react';

import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Container,
  Snackbar,
  Stack,
} from '@mui/material';

import { ApiKeySection } from '@/components/ApiKey';
import { DeepLinksSection } from '@/components/DeepLinksSection';
import { MembershipsSection } from '@/components/MembershipsSection';
import { useSetupContext } from '@/hooks/useSetupContext';
import type { Toast } from '@/types/ui';

const SettingsConfiguration = () => {
  const {
    clearCache,
    clearSettings,
    loading: setupLoading,
  } = useSetupContext();

  // ==================== STATE MANAGEMENT ====================

  // UI state
  const [toasts, setToasts] = useState<Toast[]>([]);

  const addToast = (success: boolean, message: string) => {
    const toast: Toast = {
      id: Date.now(),
      success,
      message,
      open: true,
    };
    setToasts(prev => [...prev, toast]);
  };

  const closeToast = (id: number) => {
    setToasts(prev => prev.filter(t => t.id !== id));
  };

  const handleClearCache = async () => {
    clearCache();
    addToast(true, 'Cache cleared');
  };

  const handleClearSettings = async () => {
    if (
      window.confirm(
        'Are you sure you want to clear all settings? This action cannot be undone.'
      )
    ) {
      clearSettings();
      addToast(true, 'Settings cleared');
    }
  };

  return (
    <>
      <Container maxWidth="md" sx={{ paddingY: 4 }}>
        {setupLoading ? (
          <Box
            sx={{
              display: 'flex',
              justifyContent: 'center',
              alignItems: 'center',
              height: '100vh',
            }}
          >
            <CircularProgress />
          </Box>
        ) : (
          <>
            <Box>
              <img
                src="https://app.grocerslist.com/gl-logo.png"
                alt="Grocers List"
                style={{
                  width: '200px',
                  height: 'auto',
                  marginRight: '8px',
                  marginBottom: '10px',
                }}
              />
            </Box>

            <ApiKeySection addToast={addToast} />
            <MembershipsSection />
            <DeepLinksSection addToast={addToast} />

            {/* Action Buttons */}
            <Stack direction="row" spacing={2} sx={{ mt: 4 }}>
              <Button variant="contained" onClick={handleClearCache}>
                Clear Cache
              </Button>
              <Button variant="outlined" onClick={handleClearSettings}>
                Clear All Settings
              </Button>
            </Stack>
          </>
        )}
      </Container>

      {/* Toast Notifications */}
      {toasts.map((toast, index) => (
        <Snackbar
          key={toast.id}
          open={toast.open}
          autoHideDuration={3000}
          onClose={() => closeToast(toast.id)}
          anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
          sx={{ mb: `${index * 60}px` }}
        >
          <Alert
            severity={toast.success ? 'success' : 'error'}
            sx={{ width: '100%' }}
          >
            {toast.message}
          </Alert>
        </Snackbar>
      ))}

      {/* Shake animation CSS */}
      <style>{`
        @keyframes shake {
          0%, 100% { transform: translateX(0); }
          10%, 30%, 50%, 70%, 90% { transform: translateX(-10px); }
          20%, 40%, 60%, 80% { transform: translateX(10px); }
        }
        .shake {
          animation: shake 0.5s;
        }
      `}</style>
    </>
  );
};

export default SettingsConfiguration;
