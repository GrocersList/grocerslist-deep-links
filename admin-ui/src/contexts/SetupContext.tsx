import { createContext, useEffect, useState } from 'react';

import { getGrocersListApi } from '../api/apiFactory';
import type { IGrocersListApi } from '../api/IGrocersListApi';

export interface ICreatorSettings {
  memberships: {
    enabled: boolean;
    alwaysShowTopBar: boolean;
    priceMonthly: number;
    priceYearly: number;
    branding: {
      topBar: {
        backgroundColor: string;
        buttonBackgroundColor: string;
        buttonFont: string;
        buttonTextColor: string;
        cta: string;
        font: string;
        textColor: string;
      };
      gatingCard: {
        backgroundColor: string;
        buttonBackgroundColor: string;
        buttonFont: string;
        buttonTextColor: string;
        bodyFont: string;
        description: string;
        header: string;
        headingFont: string;
        textColor: string;
      };
    };
  };
}

export interface ICreatorProvisioningSettings {
  appLinks: {
    hasAppLinksAddon: boolean;
  };
  memberships: {
    hasPriceIds: boolean;
    hasProductId: boolean;
    hasPaymentAccount: boolean;
  };
}

export const SetupContext = createContext<{
  apiKey: string;
  setApiKey: (key: string) => void;
  useLinkstaLinks: boolean;
  setUseLinkstaLinks: (v: boolean) => void;
  loading: boolean;
  clearCache: () => void;
  clearSettings: () => void;
  api: IGrocersListApi;
  creatorSettings: ICreatorSettings;
  creatorProvisioningSettings: ICreatorProvisioningSettings;
}>({
  apiKey: '',
  setApiKey: () => {},
  useLinkstaLinks: true,
  setUseLinkstaLinks: () => {},
  loading: true,
  clearCache: () => {},
  clearSettings: () => {},
  api: {} as IGrocersListApi,
  creatorSettings: {
    memberships: {
      enabled: false,
      priceMonthly: 0,
      priceYearly: 0,
      branding: {
        topBar: {
          backgroundColor: '',
          buttonBackgroundColor: '',
          buttonFont: '',
          buttonTextColor: '',
          cta: '',
          font: '',
          textColor: '',
        },
        gatingCard: {
          backgroundColor: '',
          buttonBackgroundColor: '',
          buttonFont: '',
          buttonTextColor: '',
          bodyFont: '',
          description: '',
          header: '',
          headingFont: '',
          textColor: '',
        },
      },
      alwaysShowTopBar: false,
    },
  },
  creatorProvisioningSettings: {
    appLinks: {
      hasAppLinksAddon: false,
    },
    memberships: {
      hasPriceIds: false,
      hasProductId: false,
      hasPaymentAccount: false,
    },
  },
});

export const SetupProvider = ({ children }: { children: any }) => {
  const [apiKey, setApiKey] = useState('');
  const [useLinkstaLinks, setUseLinkstaLinks] = useState<boolean>(true);
  const [loading, setLoading] = useState<boolean>(true);
  const api = getGrocersListApi();

  useEffect(() => {
    const fetchState = async () => {
      setLoading(true);
      try {
        const state = await api.getState();
        setApiKey(state.apiKey);
        setUseLinkstaLinks(
          state.useLinkstaLinks !== undefined ? state.useLinkstaLinks : true
        );
      } finally {
        setLoading(false);
      }
    };

    fetchState();
  }, [api]);

  const clearCache = async () => {
    await api.clearCache();
    window.location.reload();
  };

  const clearSettings = async () => {
    await api.clearSettings();
    window.location.reload();
  };

  return (
    <SetupContext.Provider
      value={{
        apiKey,
        setApiKey,
        useLinkstaLinks,
        setUseLinkstaLinks,
        loading,
        api,
        clearCache,
        clearSettings,
        creatorSettings: window.grocersList.settings,
        creatorProvisioningSettings: window.grocersList.provisioning || {
          appLinks: {
            hasAppLinksAddon: false,
          },
          memberships: {
            hasPriceIds: false,
            hasProductId: false,
            hasPaymentAccount: false,
          },
        },
      }}
    >
      {children}
    </SetupContext.Provider>
  );
};
