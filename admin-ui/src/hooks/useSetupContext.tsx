import { useContext } from 'react';

import { SetupContext } from '../contexts/SetupContext.tsx';

export const useSetupContext = () => useContext(SetupContext);
