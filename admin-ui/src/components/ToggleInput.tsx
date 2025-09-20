import { Box, FormControlLabel, Switch, Typography } from '@mui/material';

interface ToggleInputProps {
  label: string;
  description?: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
}

export const ToggleInput = ({
  label,
  description,
  checked,
  onChange,
  disabled = false,
}: ToggleInputProps) => (
  <Box>
    <FormControlLabel
      control={
        <Switch
          checked={checked}
          onChange={e => onChange(e.target.checked)}
          disabled={disabled}
        />
      }
      label={
        <Box>
          <Typography variant="body1">{label}</Typography>
          {description && (
            <Typography variant="caption" color="text.secondary">
              {description}
            </Typography>
          )}
        </Box>
      }
    />
  </Box>
);
