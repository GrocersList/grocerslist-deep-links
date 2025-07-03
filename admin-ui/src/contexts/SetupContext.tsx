import { createContext } from 'preact';
import { useState, useEffect } from 'preact/hooks';
import { getGrocersListApi } from '../api/apiFactory';
import type { IGrocersListApi } from '../api/IGrocersListApi';

export const SetupContext = createContext<{
  apiKey: string;
  setApiKey: (key: string) => void;
  autoRewriteEnabled: boolean;
  setAutoRewriteEnabled: (v: boolean) => void;
  useLinkstaLinks: boolean;
  setUseLinkstaLinks: (v: boolean) => void;
  setupComplete?: boolean;
  setSetupComplete: (v: boolean) => void;
  loading: boolean;
  clearSettings: () => void;
  api: IGrocersListApi;
}>({
  apiKey: '',
  setApiKey: () => {},
  autoRewriteEnabled: false,
  setAutoRewriteEnabled: () => {},
  useLinkstaLinks: true,
  setUseLinkstaLinks: () => {},
  setupComplete: false,
  setSetupComplete: () => {},
  loading: true,
  clearSettings: () => {},
  api: {} as IGrocersListApi,
});

export const SetupProvider = ({ children }: { children: any }) => {
  const [apiKey, setApiKey] = useState('');
  const [autoRewriteEnabled, setAutoRewriteEnabled] = useState<boolean>(false);
  const [useLinkstaLinks, setUseLinkstaLinks] = useState<boolean>(true);
  const [setupComplete, setSetupComplete] = useState<boolean>();
  const [loading, setLoading] = useState<boolean>(true);
  const api = getGrocersListApi();

  useEffect(() => {
    const fetchState = async () => {
      setLoading(true)
      try {
        const state = await api.getState();
        console.log('Fetched state', state);
        setApiKey(state.apiKey);
        setAutoRewriteEnabled(state.autoRewriteEnabled);
        setUseLinkstaLinks(state.useLinkstaLinks !== undefined ? state.useLinkstaLinks : true);
        setSetupComplete(state.setupComplete);
      } finally {
        setLoading(false);
      }
    };

    fetchState();
  }, [api]);

  const clearSettings = async () => {
    await api.clearSettings();
    window.location.reload()
  };

  return (
    <SetupContext.Provider
      value={{
        apiKey,
        setApiKey,
        autoRewriteEnabled,
        setAutoRewriteEnabled,
        useLinkstaLinks,
        setUseLinkstaLinks,
        setupComplete,
        setSetupComplete,
        loading,
        api,
        clearSettings
      }}
    >
      {children}
    </SetupContext.Provider>
  );
};
