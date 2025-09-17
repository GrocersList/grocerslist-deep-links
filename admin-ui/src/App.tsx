import { GrocersListSettingsPage } from './components/MainPage.tsx';
import { SetupProvider } from './contexts/SetupContext.tsx';

export const App = () => {
  return (
    <SetupProvider>
      <GrocersListSettingsPage />
    </SetupProvider>
  );
};
