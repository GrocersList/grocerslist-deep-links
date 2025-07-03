import {SetupProvider} from "./contexts/SetupContext.tsx";
import {GrocersListSettingsPage} from "./components/MainPage.tsx";

export const App = () => {
  return (
    <SetupProvider>
      <GrocersListSettingsPage />
    </SetupProvider>
  );
};