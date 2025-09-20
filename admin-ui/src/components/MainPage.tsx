import { ClipLoader } from 'react-spinners';

import Box from '@mui/material/Box';

import { useSetupContext } from '../hooks/useSetupContext';

import SettingsPage from './Settings';

export const MainPage = () => {
  const { loading } = useSetupContext();

  if (loading) {
    return (
      <Box
        sx={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          height: '100vh',
        }}
      >
        <ClipLoader />
      </Box>
    );
  }

  return <SettingsPage />;
};
