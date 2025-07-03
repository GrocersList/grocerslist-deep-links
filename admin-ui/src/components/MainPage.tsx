import { useSetupContext } from '../hooks/useSetupContext'
import { SettingsPage } from './SettingsPage';
import { SetupWizard } from './SetupWizard';
import { ClipLoader } from 'react-spinners';
import Box from '@mui/material/Box';

export const GrocersListSettingsPage = () => {
  return <MainPage />;
};

const MainPage = () => {
  const { loading, setupComplete } = useSetupContext();

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

  return setupComplete ? <SettingsPage /> : <SetupWizard />;
};