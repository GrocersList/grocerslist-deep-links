import * as React from 'react';

import { Box, Card, CardContent, Divider, Typography } from '@mui/material';

interface SectionProps {
  title: string;
  icon?: React.ReactNode;
  children: React.ReactNode;
  disabled?: boolean;
}

export const Section = ({
  title,
  icon,
  children,
  disabled = false,
}: SectionProps) => (
  <Card
    sx={{
      mb: 3,
      opacity: disabled ? 0.6 : 1,
      transition: 'opacity 0.3s ease',
    }}
  >
    <CardContent>
      <Box display="flex" alignItems="center" mb={2}>
        {icon && (
          <Box mr={1} display="flex">
            {icon}
          </Box>
        )}
        <Typography variant="h6" component="h2">
          {title}
        </Typography>
      </Box>
      <Divider sx={{ mb: 2 }} />
      {children}
    </CardContent>
  </Card>
);
