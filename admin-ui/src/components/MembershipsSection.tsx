import { useState } from 'react';

import { AttachMoney } from '@mui/icons-material';
import { Stack } from '@mui/material';

import { MembershipsZeroState } from './MembershipsZeroState';
import { Section } from './Section';
import { ToggleInput } from './ToggleInput';
import { useSetupContext } from '@/hooks/useSetupContext';

export const MembershipsSection = () => {
  const { api, creatorSettings, creatorProvisioningSettings } =
    useSetupContext();

  const membershipsProvisioning =
    creatorProvisioningSettings?.memberships || {};
  const { hasPriceIds, hasProductId, hasPaymentAccount } =
    membershipsProvisioning || {};
  const hasMembershipsAccess = hasPriceIds && hasProductId && hasPaymentAccount;

  // ==================== STATE MANAGEMENT ====================

  // UI state
  const [isMembershipsEnabled, setIsMembershipsEnabled] = useState(
    creatorSettings?.memberships?.enabled
  );

  const handleSetIsMembershipsEnabled = async (enabled: boolean) => {
    await api.updateMembershipsEnabled(enabled);
    setIsMembershipsEnabled(enabled);
  };

  return (
    <Section
      title="Memberships Settings"
      icon={<AttachMoney color="primary" />}
    >
      <Stack spacing={2}>
        {!hasMembershipsAccess ? (
          <MembershipsZeroState />
        ) : (
          <ToggleInput
            label="Enable Memberships"
            description="Enable Memberships for your site"
            checked={isMembershipsEnabled}
            onChange={() =>
              handleSetIsMembershipsEnabled(!isMembershipsEnabled)
            }
          />
        )}
      </Stack>
    </Section>
  );
};
